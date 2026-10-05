<?php
/*
** Zabbix
** Copyright (C) 2001-2026 Zabbix SIA
**
** This program is free software: you can redistribute it and/or modify it under the terms of
** the GNU Affero General Public License as published by the Free Software Foundation, version 3.
**
** This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
** without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
** See the GNU Affero General Public License for more details.
**
** You should have received a copy of the GNU Affero General Public License along with this program.
** If not, see <https://www.gnu.org/licenses/>.
**/


require_once dirname(__FILE__).'/include/classes/user/CWebUser.php';

require_once dirname(__FILE__).'/include/config.inc.php';

$page['file'] = 'topo_tree.php';

if (!CWebUser::isLoggedIn() || CWebUser::isGuest()) {
	redirect('index.php');
}

$is_admin = (CWebUser::$data['type'] == USER_TYPE_SUPER_ADMIN);

/**
 * Load the whole topology: nodes (groups + devices) and links.
 */
function topoLoad(): array {
	$nodes = [];
	$res = DBselect('SELECT topo_nodeid AS nodeid, type, name, parentid, hostid, posx, posy FROM topo_node ORDER BY topo_nodeid');

	while ($row = DBfetch($res)) {
		$nodes[(int) $row['nodeid']] = [
			'nodeid'   => (int) $row['nodeid'],
			'type'     => $row['type'],
			'name'     => $row['name'],
			'parentid' => $row['parentid'] !== null ? (int) $row['parentid'] : null,
			'hostid'   => $row['hostid'] !== null ? (int) $row['hostid'] : null,
			'posx'     => (int) $row['posx'],
			'posy'     => (int) $row['posy']
		];
	}

	$links = [];
	$res = DBselect('SELECT topo_linkid AS linkid, nodeida, nodeidb FROM topo_link ORDER BY topo_linkid');

	while ($row = DBfetch($res)) {
		$links[] = [
			'linkid' => (int) $row['linkid'],
			'a'      => (int) $row['nodeida'],
			'b'      => (int) $row['nodeidb']
		];
	}

	return [$nodes, $links];
}

/**
 * Worst active-problem severity per node. Groups roll up their members.
 * Severity -1 means "no open problems".
 */
function topoStatuses(array $nodes): array {
	$host_sev = [];
	$res = DBselect('SELECT i.hostid, MAX(p.severity) AS sev, COUNT(*) AS cnt'.
			' FROM problem p JOIN functions f ON f.triggerid = p.objectid'.
			' JOIN items i ON i.itemid = f.itemid'.
			' WHERE p.r_eventid IS NULL GROUP BY i.hostid');

	while ($row = DBfetch($res)) {
		$host_sev[(int) $row['hostid']] = ['sev' => (int) $row['sev'], 'cnt' => (int) $row['cnt']];
	}

	$status = [];

	foreach ($nodes as $node) {
		if ($node['type'] === 'device') {
			$status[$node['nodeid']] = ($node['hostid'] !== null && isset($host_sev[$node['hostid']]))
				? $host_sev[$node['hostid']]
				: ['sev' => -1, 'cnt' => 0];
		}
	}

	foreach ($nodes as $node) {
		if ($node['type'] === 'group') {
			$sev = -1;
			$cnt = 0;

			foreach ($nodes as $member) {
				if ($member['type'] === 'device' && $member['parentid'] === $node['nodeid']
						&& isset($status[$member['nodeid']])) {
					$sev = max($sev, $status[$member['nodeid']]['sev']);
					$cnt += $status[$member['nodeid']]['cnt'];
				}
			}

			$status[$node['nodeid']] = ['sev' => $sev, 'cnt' => $cnt];
		}
	}

	return $status;
}

$ajax = (getRequest('ajax') === '1');

// Read-only status feed for the auto-refresh poller.
if ($ajax && getRequest('mode') === 'status') {
	[$nodes] = topoLoad();
	session_write_close();

	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode(['statuses' => topoStatuses($nodes)], JSON_UNESCAPED_UNICODE);
	exit;
}

// Write actions, Super admin only, CSRF protected, JSON answers.
if ($ajax) {
	session_write_close();
	header('Content-Type: application/json; charset=UTF-8');

	$reply = static function (bool $ok, string $msg = ''): void {
		echo json_encode(['ok' => $ok, 'msg' => $msg], JSON_UNESCAPED_UNICODE);
		exit;
	};

	if (!$is_admin) {
		$reply(false, 'ต้องเป็น Super admin จึงจะแก้ไขแผนผังได้');
	}

	if (!CCsrfTokenHelper::check(getRequest('csrf_token', ''), 'topo_tree.php')) {
		$reply(false, 'CSRF token ไม่ถูกต้อง รีเฟรชหน้าแล้วลองใหม่');
	}

	$mode = getRequest('mode', '');

	if ($mode === 'add_group') {
		$name = trim(getRequest('name', ''));

		if ($name === '' || mb_strlen($name) > 64) {
			$reply(false, 'ชื่อกลุ่มต้องมี 1-64 ตัวอักษร');
		}

		if (!DBexecute('INSERT INTO topo_node (type, name, posx, posy) VALUES (\'group\', '.zbx_dbstr($name).', '
			.max(0, (int) getRequest('x', 60)).', '.max(0, (int) getRequest('y', 60)).')')) {
			$reply(false, 'เขียนฐานข้อมูลไม่สำเร็จ (ตรวจสิทธิ์ของ user DB)');
		}

		$reply(true, 'เพิ่มกลุ่มแล้ว');
	}

	if ($mode === 'add_device') {
		$name = trim(getRequest('name', ''));
		$hostid = (int) getRequest('hostid', 0);
		$groupid = (int) getRequest('groupid', 0);

		if ($name === '' || mb_strlen($name) > 64) {
			$reply(false, 'ชื่ออุปกรณ์ต้องมี 1-64 ตัวอักษร');
		}

		if ($hostid > 0 && !API::Host()->get(['hostids' => $hostid, 'filter' => ['status' => HOST_STATUS_MONITORED],
				'countOutput' => true])) {
			$reply(false, 'ไม่พบ host ที่เลือกใน Zabbix');
		}

		if ($groupid > 0) {
			$group = DBfetch(DBselect('SELECT topo_nodeid FROM topo_node WHERE topo_nodeid = '.$groupid.
					' AND type = \'group\''));

			if ($group === false) {
				$reply(false, 'ไม่พบกลุ่มที่เลือก');
			}
		}

		if (!DBexecute('INSERT INTO topo_node (type, name, parentid, hostid, posx, posy) VALUES (\'device\', '
			.zbx_dbstr($name).', '.($groupid > 0 ? $groupid : 'NULL').', '.($hostid > 0 ? $hostid : 'NULL').', '
			.max(0, (int) getRequest('x', 120)).', '.max(0, (int) getRequest('y', 120)).')')) {
			$reply(false, 'เขียนฐานข้อมูลไม่สำเร็จ (ตรวจสิทธิ์ของ user DB)');
		}

		$reply(true, 'เพิ่มอุปกรณ์แล้ว');
	}

	if ($mode === 'add_link') {
		$a = (int) getRequest('a', 0);
		$b = (int) getRequest('b', 0);

		if ($a <= 0 || $b <= 0 || $a === $b) {
			$reply(false, 'เลือกโหนดต้นทางและปลายทางให้ถูกต้อง');
		}

		$exists = DBfetch(DBselect('SELECT n1.topo_nodeid AS a, n2.topo_nodeid AS b FROM topo_node n1'.
				' JOIN topo_node n2 ON n2.topo_nodeid = '.$b.' WHERE n1.topo_nodeid = '.$a));

		if ($exists === false) {
			$reply(false, 'ไม่พบโหนดที่เลือก');
		}

		if (!DBexecute('INSERT INTO topo_link (nodeida, nodeidb) VALUES ('.$a.', '.$b.')')) {
			$reply(false, 'เส้นเชื่อมนี้มีอยู่แล้วหรือเขียนฐานข้อมูลไม่สำเร็จ');
		}

		$reply(true, 'เพิ่มเส้นเชื่อมแล้ว');
	}

	if ($mode === 'delete_node') {
		$nodeid = (int) getRequest('nodeid', 0);

		if (!DBfetch(DBselect('SELECT topo_nodeid FROM topo_node WHERE topo_nodeid = '.$nodeid))) {
			$reply(false, 'ไม่พบโหนดที่จะลบ');
		}

		// Ungroup members first so deleting a group keeps its devices on the canvas.
		DBexecute('UPDATE topo_node SET parentid = NULL WHERE parentid = '.$nodeid);
		DBexecute('DELETE FROM topo_node WHERE topo_nodeid = '.$nodeid);
		$reply(true, 'ลบโหนดแล้ว');
	}

	if ($mode === 'delete_link') {
		$linkid = (int) getRequest('linkid', 0);

		DBexecute('DELETE FROM topo_link WHERE topo_linkid = '.$linkid);
		$reply(true, 'ลบเส้นเชื่อมแล้ว');
	}

	if ($mode === 'move') {
		$nodeid = (int) getRequest('nodeid', 0);
		$x = max(0, (int) getRequest('x', 0));
		$y = max(0, (int) getRequest('y', 0));

		DBexecute('UPDATE topo_node SET posx = '.$x.', posy = '.$y.' WHERE topo_nodeid = '.$nodeid);
		$reply(true);
	}

	$reply(false, 'ไม่รู้จักคำสั่ง');
}

[$nodes, $links] = topoLoad();
$statuses = topoStatuses($nodes);

// Hosts for the "add device" picker (Super admin only).
$hosts = $is_admin
	? API::Host()->get([
		'output' => ['hostid', 'name'],
		'filter' => ['status' => HOST_STATUS_MONITORED],
		'sortfield' => 'name'
	])
	: [];

$host_names = [];
foreach ($hosts as $host) {
	$host_names[$host['hostid']] = $host['name'];
}

$csrf_token = CCsrfTokenHelper::get('topo_tree.php');

$data = [
	'nodes' => array_values($nodes),
	'links' => $links,
	'statuses' => $statuses,
	'host_names' => $host_names,
	'is_admin' => $is_admin,
	'csrf_token' => $csrf_token
];

session_write_close();

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="th">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Topology — Zabbix</title>
	<link rel="stylesheet" type="text/css" href="assets/styles/modern-theme.css">
	<style>
		:root {
			color-scheme: dark;
			--bg: #0a1120;
			--surface: #101a2e;
			--surface-2: #16233c;
			--line: #24344f;
			--line-soft: #1a2942;
			--text: #dce8f7;
			--text-dim: #8296b3;
			--accent: #3fa2ff;
			--accent-soft: rgba(63, 162, 255, .16);
			--ok: #38d17e;
			--danger: #e45959;
		}

		body { background: var(--bg); margin: 0; font-family: Sarabun, Arial, Tahoma, sans-serif;
			color: var(--text); font-size: 14px; }
		.mono { font-family: ui-monospace, 'Cascadia Code', Consolas, monospace; }

		.topo { display: flex; flex-direction: column; height: 100vh; }

		/* ---- top bar ---- */
		.topo .topbar { background: linear-gradient(180deg, var(--surface-2), var(--surface));
			border-bottom: 1px solid var(--line); padding: 10px 18px;
			display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
		.topo .topbar .mark { width: 26px; height: 26px; flex: 0 0 auto; }
		.topo .topbar h1 { font-size: 17px; margin: 0; font-weight: 700; letter-spacing: .01em; }
		.topo .topbar .sub { color: var(--text-dim); font-size: 12px; margin-top: 1px; }
		.topo .topbar .spacer { flex: 1; }
		.topo .topbar .back { color: var(--accent); text-decoration: none; font-size: 13.5px; }
		.topo .topbar .back:hover { text-decoration: underline; }
		.topo .live { display: inline-flex; align-items: center; gap: 7px; font-size: 12px;
			color: var(--text-dim); border: 1px solid var(--line); border-radius: 20px; padding: 5px 12px;
			background: rgba(16, 26, 46, .6); }
		.topo .live .live-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--ok);
			box-shadow: 0 0 8px var(--ok); animation: livepulse 2.4s ease-in-out infinite; }
		@keyframes livepulse { 0%, 100% { opacity: 1; } 50% { opacity: .35; } }

		/* ---- buttons ---- */
		.topo .tbtn { border: 1px solid transparent; border-radius: 8px; padding: 8px 16px; cursor: pointer;
			font-size: 13.5px; font-family: inherit; height: auto; line-height: 1.5; white-space: nowrap;
			display: inline-flex; align-items: center; justify-content: center;
			background: linear-gradient(180deg, #2f8fe6, #1e6fc0); color: #fff;
			transition: filter .15s, box-shadow .15s, border-color .15s, color .15s; }
		.topo .tbtn:hover { filter: brightness(1.08); }
		.topo .tbtn:focus-visible, .topo .panel input:focus-visible, .topo .panel select:focus-visible {
			outline: 2px solid var(--accent); outline-offset: 2px; }
		.topo .tbtn.tbtn-ghost { background: transparent; color: #b9c9de; border-color: var(--line); }
		.topo .tbtn.tbtn-ghost.is-active { color: #fff; border-color: var(--accent);
			box-shadow: 0 0 0 3px var(--accent-soft), inset 0 0 12px rgba(63, 162, 255, .08); }
		.topo .tbtn.tbtn-ghost.is-active.is-danger { border-color: var(--danger);
			box-shadow: 0 0 0 3px rgba(228, 89, 89, .16), inset 0 0 12px rgba(228, 89, 89, .08); }
		.topo .tbtn:disabled { opacity: .45; cursor: not-allowed; }

		/* ---- add panels ---- */
		.topo .panel { background: var(--surface); border-bottom: 1px solid var(--line-soft);
			padding: 14px 18px; display: none; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
		.topo .panel.is-open { display: flex; }
		.topo .panel label { display: block; font-size: 12px; color: var(--text-dim); margin-bottom: 5px; }
		.topo .panel input, .topo .panel select { font-family: inherit; font-size: 13.5px; padding: 8px 11px;
			border: 1px solid #2a3d5e; border-radius: 8px; min-width: 210px; background-color: #0c1526;
			color: var(--text); height: auto; line-height: 1.5; }
		/* global theme forces select height 24px + white bg — reset fully and draw our own arrow */
		.topo .panel select { appearance: none; -webkit-appearance: none; padding-right: 30px;
			background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%238296b3' stroke-width='1.6' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
			background-repeat: no-repeat; background-position: right 11px center; }
		.topo .panel select option { background-color: #101a2e; color: var(--text); }

		/* ---- map area ---- */
		.topo .map-area { flex: 1; position: relative; min-height: 0; }
		.topo .viewport { position: absolute; inset: 0; overflow: auto; }
		.topo .canvas { position: relative; min-width: 100%; min-height: 100%; background-image:
			radial-gradient(1100px 640px at 46% 38%, rgba(63, 162, 255, .06), transparent 62%),
			radial-gradient(#1b2a45 1px, transparent 1.3px);
			background-size: auto, 26px 26px; }
		.topo .viewport { box-shadow: inset 0 0 130px rgba(2, 6, 14, .55); }
		.topo .viewport::-webkit-scrollbar { width: 10px; height: 10px; }
		.topo .viewport::-webkit-scrollbar-thumb { background: #22344f; border-radius: 6px; }
		.topo .viewport::-webkit-scrollbar-track { background: transparent; }
		.topo .canvas.is-linkmode { cursor: crosshair; }
		.topo .canvas.is-delmode { cursor: not-allowed; }
		.topo .canvas.is-linkmode .node, .topo .canvas.is-delmode .node,
		.topo .canvas.is-linkmode .gbox, .topo .canvas.is-delmode .gbox { cursor: crosshair; }
		.topo .canvas.is-delmode .gbox { cursor: not-allowed; }

		.topo svg.links { position: absolute; inset: 0; z-index: 2; pointer-events: none; }
		.topo svg.links .link-hit { pointer-events: stroke; stroke: transparent; stroke-width: 14;
			cursor: pointer; }
		.topo .canvas.is-delmode svg.links .link-hit:hover { stroke: rgba(228, 89, 89, .35); }

		/* ---- group boxes ---- */
		.topo .gbox { position: absolute; z-index: 1; border: 1px dashed rgba(63, 162, 255, .38);
			border-radius: 14px;
			background: linear-gradient(180deg, rgba(63, 162, 255, .07), rgba(63, 162, 255, .02)); }
		.topo .gbox .glabel { position: absolute; top: -12px; left: 14px; background: #0e2238;
			color: #7fb8f0; font-size: 12px; font-weight: 700; padding: 2px 11px; border-radius: 10px;
			border: 1px solid rgba(63, 162, 255, .35); white-space: nowrap; }
		.topo .gbox.is-empty { border-style: dashed; background: transparent; cursor: move; }
		.topo .gbox.is-empty .glabel { border-style: dashed; }

		/* ---- nodes ---- */
		.topo .node { position: absolute; z-index: 3; width: 170px; height: 56px; padding: 8px 10px 8px 12px;
			box-sizing: border-box; border-radius: 10px;
			background: linear-gradient(180deg, #14203a, #101a2e);
			border: 1px solid #2a3d5e;
			box-shadow: 0 2px 10px rgba(1, 5, 12, .5), inset 0 1px 0 rgba(255, 255, 255, .04);
			transition: border-color .15s, box-shadow .15s; }
		.topo .node:hover { border-color: #3d5880; }
		.topo .node.is-dev { cursor: move; }
		.topo .node .n-head { display: flex; align-items: center; gap: 7px; }
		.topo .node .dot { width: 11px; height: 11px; border-radius: 50%; flex: 0 0 auto;
			background: var(--ok); box-shadow: 0 0 8px rgba(56, 209, 126, .55); }
		.topo .node .dot.is-alert { animation: livepulse 1.4s ease-in-out infinite; }
		.topo .node .n-name { font-weight: 700; font-size: 13px; white-space: nowrap; overflow: hidden;
			text-overflow: ellipsis; }
		.topo .node .n-sub { font-size: 11px; color: var(--text-dim); margin-top: 3px; white-space: nowrap;
			overflow: hidden; text-overflow: ellipsis; padding-left: 18px; }
		.topo .node .n-badge { margin-left: auto; background: var(--danger); color: #fff; font-size: 10.5px;
			border-radius: 9px; padding: 1px 7px; flex: 0 0 auto; }
		.topo .node.is-src { border-color: var(--accent);
			box-shadow: 0 0 0 3px var(--accent-soft), 0 0 18px rgba(63, 162, 255, .25); }
		.topo .canvas.is-delmode .node:hover { border-color: var(--danger);
			box-shadow: 0 0 0 3px rgba(228, 89, 89, .18); }
		.topo .canvas.is-delmode .gbox:hover { border-color: var(--danger); }
		.topo .node.is-chip { width: 130px; height: 40px; display: flex; align-items: center; gap: 8px;
			cursor: move; }
		.topo .node.is-chip .n-sub { display: none; }

		/* ---- HUD legend ---- */
		.topo .hud { position: absolute; left: 14px; bottom: 14px; z-index: 6; display: flex; gap: 12px;
			align-items: center; flex-wrap: wrap; max-width: calc(100% - 28px);
			background: rgba(10, 17, 32, .85); backdrop-filter: blur(6px);
			border: 1px solid var(--line); border-radius: 10px; padding: 8px 14px;
			font-size: 11.5px; color: var(--text-dim); }
		.topo .hud .dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block;
			margin-right: 5px; vertical-align: middle; }
		.topo .hud b { font-weight: 600; color: var(--text); }

		/* ---- empty state ---- */
		.topo .empty { position: absolute; inset: 0; display: none; place-items: center; z-index: 4;
			pointer-events: none; text-align: center; }
		.topo .empty.is-on { display: grid; }
		.topo .empty .e-card { border: 1px dashed var(--line); border-radius: 14px; padding: 26px 38px;
			background: rgba(16, 26, 46, .5); color: var(--text-dim); font-size: 14px; }
		.topo .empty .e-card strong { display: block; font-size: 15.5px; color: var(--text); margin-bottom: 4px; }

		/* ---- toast ---- */
		.topo .toast { position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
			background: rgba(13, 21, 38, .95); color: var(--text); padding: 11px 20px; border-radius: 10px;
			border: 1px solid var(--line); border-left: 3px solid var(--accent); font-size: 13.5px;
			box-shadow: 0 6px 22px rgba(0, 0, 0, .45); opacity: 0; transition: opacity .25s; z-index: 50;
			pointer-events: none; max-width: 80vw; }
		.topo .toast.is-on { opacity: 1; }

		@media (prefers-reduced-motion: reduce) {
			.topo .live .live-dot, .topo .node .dot.is-alert { animation: none; }
		}
	</style>
</head>
<body>
<div class="topo">
	<div class="topbar">
		<svg class="mark" viewBox="0 0 26 26" aria-hidden="true">
			<line x1="6" y1="7" x2="19" y2="13" stroke="#3fa2ff" stroke-width="1.4" opacity=".7"/>
			<line x1="6" y1="19" x2="19" y2="13" stroke="#3fa2ff" stroke-width="1.4" opacity=".7"/>
			<circle cx="6" cy="7" r="3" fill="#38d17e"/>
			<circle cx="6" cy="19" r="3" fill="#38d17e"/>
			<circle cx="19" cy="13" r="3" fill="#3fa2ff"/>
		</svg>
		<div>
			<h1>แผนผังเครือข่าย (Hybrid Topology)</h1>
			<div class="sub">จัดวางและเชื่อมโหนดได้อิสระ สถานะตามระดับปัญหาจริงของ host</div>
		</div>
		<span class="spacer"></span>
		<span class="live"><span class="live-dot"></span>สด <span class="mono" id="live-time"></span></span>
<?php if ($is_admin): ?>
		<button type="button" class="tbtn" id="btn-add-group">+ กลุ่ม</button>
		<button type="button" class="tbtn" id="btn-add-device">+ อุปกรณ์</button>
		<button type="button" class="tbtn tbtn-ghost" id="btn-link">โหมดเชื่อมเส้น</button>
		<button type="button" class="tbtn tbtn-ghost is-danger" id="btn-delete">โหมดลบ</button>
<?php else: ?>
		<span class="sub" style="padding: 4px 10px; border: 1px solid var(--line-soft); border-radius: 8px;">
			ดูแบบอย่างเดียว — เฉพาะ Super admin จึงแก้ไขได้</span>
<?php endif ?>
		<a class="back" href="zabbix.php">← กลับหน้าหลัก</a>
	</div>

<?php if ($is_admin): ?>
	<div class="panel" id="panel-group">
		<div>
			<label for="group-name">ชื่อกลุ่ม</label>
			<input type="text" id="group-name" maxlength="64" placeholder="เช่น สำนักงานใหญ่, Server Room">
		</div>
		<button type="button" class="tbtn" id="btn-save-group">บันทึก</button>
		<button type="button" class="tbtn tbtn-ghost" id="btn-cancel-group">ยกเลิก</button>
	</div>
	<div class="panel" id="panel-device">
		<div>
			<label for="dev-name">ชื่อที่แสดง</label>
			<input type="text" id="dev-name" maxlength="64" placeholder="เช่น Core Switch, HR-PC-01">
		</div>
		<div>
			<label for="dev-host">ผูกกับ Host ใน Zabbix (แสดงสถานะเตือน)</label>
			<select id="dev-host">
				<option value="">— ไม่ผูก host —</option>
<?php foreach ($hosts as $host): ?>
				<option value="<?= (int) $host['hostid'] ?>"><?= htmlspecialchars($host['name'], ENT_QUOTES) ?></option>
<?php endforeach ?>
			</select>
		</div>
		<div>
			<label for="dev-group">สังกัดกลุ่ม</label>
			<select id="dev-group">
				<option value="">— ไม่สังกัดกลุ่ม —</option>
<?php foreach ($nodes as $n): if ($n['type'] !== 'group') continue; ?>
				<option value="<?= $n['nodeid'] ?>"><?= htmlspecialchars($n['name'], ENT_QUOTES) ?></option>
<?php endforeach ?>
			</select>
		</div>
		<button type="button" class="tbtn" id="btn-save-device">บันทึก</button>
		<button type="button" class="tbtn tbtn-ghost" id="btn-cancel-device">ยกเลิก</button>
	</div>
<?php endif ?>

	<div class="map-area">
		<div class="viewport" id="viewport">
			<div class="canvas" id="canvas">
				<svg class="links" id="svg"></svg>
			</div>
		</div>

		<div class="hud" id="hud"></div>

		<div class="empty" id="empty">
			<div class="e-card">
				<strong>ผืนแผนผังยังว่างอยู่</strong>
				เริ่มด้วยปุ่ม «+ กลุ่ม» หรือ «+ อุปกรณ์» ด้านบน แล้วลากวางตามใจ
			</div>
		</div>
	</div>
</div>

<div class="toast" id="toast"></div>

<script>
const DATA = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS
	| JSON_HEX_AMP | JSON_HEX_QUOT) ?>;

const SEV = [
	{c: '#97AAB3', t: 'ไม่จำแนก'},
	{c: '#7499FF', t: 'ข้อมูล'},
	{c: '#FFC859', t: 'เตือน'},
	{c: '#FFA059', t: 'เฉลี่ย'},
	{c: '#E97659', t: 'สูง'},
	{c: '#E45959', t: 'วิกฤต'}
];
const OK_COLOR = '#38d17e';
const NODE_W = 170, NODE_H = 56, CHIP_W = 130, CHIP_H = 40;
const MOTION = matchMedia('(prefers-reduced-motion: no-preference)').matches;

const canvas = document.getElementById('canvas');
const svg = document.getElementById('svg');
const viewport = document.getElementById('viewport');
const nodes = new Map(DATA.nodes.map(n => [n.nodeid, n]));
const statuses = new Map(Object.entries(DATA.statuses).map(([k, v]) => [+k, v]));

const sevColor = s => s >= 0 ? SEV[s].c : OK_COLOR;

// HUD legend
document.getElementById('hud').innerHTML =
	'<b>ระดับการแจ้งเตือน</b>' +
	[['ปกติ', OK_COLOR], ...SEV.map(s => [s.t, s.c])]
		.map(([t, c]) => '<span><span class="dot" style="background:'+c+'"></span>'+t+'</span>').join('');

if (!DATA.nodes.length) {
	document.getElementById('empty').classList.add('is-on');
}

// ---- geometry helpers ----

const membersOf = gid => [...nodes.values()].filter(n => n.type === 'device' && n.parentid === gid);
const nodeById = id => nodes.get(id);

function groupBox(g) {
	const members = membersOf(g.nodeid);

	if (!members.length) {
		return {x: g.posx, y: g.posy, w: CHIP_W, h: CHIP_H, chip: true};
	}

	let minx = Infinity, miny = Infinity, maxx = -Infinity, maxy = -Infinity;

	for (const m of members) {
		minx = Math.min(minx, m.posx); miny = Math.min(miny, m.posy);
		maxx = Math.max(maxx, m.posx + NODE_W); maxy = Math.max(maxy, m.posy + NODE_H);
	}

	return {x: minx - 18, y: miny - 44, w: maxx - minx + 36, h: maxy - miny + 62, chip: false};
}

function anchorOf(n, boxes) {
	if (n.type === 'device') {
		return {x: n.posx + NODE_W / 2, y: n.posy + NODE_H / 2};
	}

	const box = boxes[n.nodeid];
	return box
		? {x: box.x + box.w / 2, y: box.y + box.h / 2}
		: {x: n.posx + CHIP_W / 2, y: n.posy + CHIP_H / 2};
}

// ---- rendering ----

const nodeEls = new Map();
const gboxEls = {};

function makeNode(n) {
	const el = document.createElement('div');
	el.className = 'node ' + (n.type === 'group' ? 'is-chip' : 'is-dev');
	el.dataset.id = n.nodeid;
	el.style.left = n.posx + 'px';
	el.style.top = n.posy + 'px';

	const head = document.createElement('div');
	head.className = 'n-head';
	const dot = document.createElement('span');
	dot.className = 'dot';
	const name = document.createElement('span');
	name.className = 'n-name';
	name.textContent = n.name;
	head.append(dot, name);

	if (n.type === 'device') {
		const sub = document.createElement('div');
		sub.className = 'n-sub mono';
		const host = n.hostid && DATA.host_names[n.hostid] ? DATA.host_names[n.hostid] : 'ไม่ผูก host';
		sub.textContent = host;
		el.append(head, sub);
	}
	else {
		el.append(head);
	}

	canvas.appendChild(el);
	return el;
}

function makeGroupBox(g) {
	const box = groupBox(g);
	const el = document.createElement('div');
	el.className = 'gbox' + (box.chip ? ' is-empty' : '');
	el.dataset.id = g.nodeid;
	el.style.left = box.x + 'px';
	el.style.top = box.y + 'px';
	el.style.width = box.w + 'px';
	el.style.height = box.h + 'px';

	const label = document.createElement('span');
	label.className = 'glabel';
	label.textContent = box.chip ? g.name : g.name + ' (' + membersOf(g.nodeid).length + ')';
	el.appendChild(label);
	canvas.appendChild(el);

	return el;
}

function linkColor(l) {
	const sa = statuses.get(l.a), sb = statuses.get(l.b);
	const sev = Math.max(sa ? sa.sev : -1, sb ? sb.sev : -1);
	return {color: sevColor(sev), sev};
}

function drawLinks(boxes) {
	svg.setAttribute('width', canvas.scrollWidth);
	svg.setAttribute('height', canvas.scrollHeight);
	let html = '';

	for (const l of DATA.links) {
		const a = nodeById(l.a), b = nodeById(l.b);
		if (!a || !b) continue;

		const p1 = anchorOf(a, boxes), p2 = anchorOf(b, boxes);
		const {color, sev} = linkColor(l);
		const w = sev >= 4 ? 3.5 : sev >= 2 ? 2.5 : 1.8;
		const len = Math.hypot(p2.x - p1.x, p2.y - p1.y);

		// soft under-glow, then the line itself, then packets moving both ways
		html += '<line x1="'+p1.x+'" y1="'+p1.y+'" x2="'+p2.x+'" y2="'+p2.y+'" stroke="'+color+
			'" stroke-width="'+(w + 6)+'" opacity=".14"/>' +
			'<line x1="'+p1.x+'" y1="'+p1.y+'" x2="'+p2.x+'" y2="'+p2.y+'" stroke="'+color+
			'" stroke-width="'+w+'" opacity=".8"/>';

		if (MOTION && len > 60) {
			const dur = Math.max(2.2, len / 150).toFixed(2);

			html += '<circle r="2.4" fill="'+color+'" opacity=".9">' +
				'<animateMotion dur="'+dur+'s" repeatCount="indefinite" path="M'+p1.x+' '+p1.y+
				' L'+p2.x+' '+p2.y+'"/></circle>' +
				'<circle r="1.8" fill="'+color+'" opacity=".55">' +
				'<animateMotion dur="'+dur+'s" repeatCount="indefinite" keyPoints="1;0" keyTimes="0;1"'+
				' path="M'+p1.x+' '+p1.y+' L'+p2.x+' '+p2.y+'"/></circle>';
		}

		html += '<line class="link-hit" data-linkid="'+l.linkid+'" x1="'+p1.x+'" y1="'+p1.y+
			'" x2="'+p2.x+'" y2="'+p2.y+'"/>';
	}

	svg.innerHTML = html;
}

function updateGeometry() {
	const boxes = {};

	for (const n of nodes.values()) {
		if (n.type === 'group') boxes[n.nodeid] = groupBox(n);
	}

	for (const [gid, box] of Object.entries(boxes)) {
		const el = gboxEls[gid];

		if (el) {
			el.style.left = box.x + 'px';
			el.style.top = box.y + 'px';
			el.style.width = box.w + 'px';
			el.style.height = box.h + 'px';
			el.classList.toggle('is-empty', !!box.chip);
		}
	}

	drawLinks(boxes);
}

// first render: device cards, group boxes for populated groups, chips for empty groups
for (const n of nodes.values()) {
	if (n.type === 'device') {
		nodeEls.set(n.nodeid, makeNode(n));
	}
	else if (membersOf(n.nodeid).length) {
		gboxEls[n.nodeid] = makeGroupBox(n);
	}
	else {
		nodeEls.set(n.nodeid, makeNode(n));
	}
}

canvas.style.minWidth = (Math.max(1200, ...DATA.nodes.map(n => n.posx + 260))) + 'px';
canvas.style.minHeight = (Math.max(700, ...DATA.nodes.map(n => n.posy + 200))) + 'px';
updateGeometry();

// ---- status dots ----

function applyStatuses() {
	for (const n of nodes.values()) {
		const st = statuses.get(n.nodeid) || {sev: -1, cnt: 0};
		const el = n.type === 'device' ? nodeEls.get(n.nodeid) : gboxEls[n.nodeid];
		const dot = el ? el.querySelector('.dot') : null;

		if (dot) {
			dot.style.background = sevColor(st.sev);
			dot.style.boxShadow = '0 0 8px ' + sevColor(st.sev) + '99';
			dot.classList.toggle('is-alert', st.sev >= 2);
		}

		if (el && n.type === 'group') {
			el.style.borderColor = st.sev >= 4 ? sevColor(st.sev) : '';
		}

		let badge = el ? el.querySelector('.n-badge') : null;

		if (st.cnt > 0 && el) {
			if (!badge) {
				badge = document.createElement('span');
				badge.className = 'n-badge';
				(el.querySelector('.n-head') || el).appendChild(badge);
			}

			badge.textContent = st.cnt;
			badge.style.background = sevColor(st.sev);
		}
		else if (badge) {
			badge.remove();
		}
	}
}

applyStatuses();
document.getElementById('live-time').textContent = new Date().toLocaleTimeString('th-TH', {hour12: false});

// ---- drag (admin) ----

let drag = null;

canvas.addEventListener('pointerdown', e => {
	if (!DATA.is_admin) return;

	const gEl = e.target.closest('.gbox');
	const nEl = e.target.closest('.node');
	const el = nEl || gEl;

	if (!el) return;

	const id = +el.dataset.id;
	const n = nodeById(id);
	if (!n) return;

	drag = {id, el, sx: e.clientX, sy: e.clientY, moved: false, members: [], ox: 0, oy: 0};

	if (n.type === 'group') {
		drag.members = membersOf(id).map(m => ({ref: m, ox: m.posx, oy: m.posy}));
	}
	else {
		drag.ox = n.posx;
		drag.oy = n.posy;
	}

	el.setPointerCapture(e.pointerId);
	e.preventDefault();
});

canvas.addEventListener('pointermove', e => {
	if (!drag) return;

	const dx = e.clientX - drag.sx, dy = e.clientY - drag.sy;

	if (!drag.moved && Math.abs(dx) + Math.abs(dy) < 3) return;

	drag.moved = true;

	if (drag.members.length) {
		for (const {ref, ox, oy} of drag.members) {
			ref.posx = Math.max(0, ox + dx);
			ref.posy = Math.max(0, oy + dy);

			const mel = nodeEls.get(ref.nodeid);

			if (mel) {
				mel.style.left = ref.posx + 'px';
				mel.style.top = ref.posy + 'px';
			}
		}
	}
	else {
		const n = nodeById(drag.id);
		n.posx = Math.max(0, drag.ox + dx);
		n.posy = Math.max(0, drag.oy + dy);
		drag.el.style.left = n.posx + 'px';
		drag.el.style.top = n.posy + 'px';
	}

	updateGeometry();
});

canvas.addEventListener('pointerup', () => {
	if (!drag) return;

	if (drag.moved) {
		const persist = drag.members.length ? drag.members.map(m => m.ref) : [nodeById(drag.id)];

		for (const m of persist) {
			post({mode: 'move', nodeid: m.nodeid, x: m.posx, y: m.posy}, true);
		}

		canvas.style.minWidth = Math.max(1200, ...[...nodes.values()].map(n => n.posx + 260)) + 'px';
		canvas.style.minHeight = Math.max(700, ...[...nodes.values()].map(n => n.posy + 200)) + 'px';
	}

	drag = null;
});

// ---- modes: link / delete ----

let mode = null, linkSrc = null;

function setMode(next) {
	mode = mode === next ? null : next;
	linkSrc = null;

	document.getElementById('btn-link')?.classList.toggle('is-active', mode === 'link');
	document.getElementById('btn-delete')?.classList.toggle('is-active', mode === 'delete');
	canvas.classList.toggle('is-linkmode', mode === 'link');
	canvas.classList.toggle('is-delmode', mode === 'delete');

	document.querySelectorAll('.node.is-src').forEach(el => el.classList.remove('is-src'));

	if (mode) toast(mode === 'link' ? 'คลิกโหนดต้นทาง แล้วคลิกโหนดปลายทาง' : 'คลิกโหนดหรือเส้นเชื่อมที่จะลบ');
}

document.getElementById('btn-link')?.addEventListener('click', () => setMode('link'));
document.getElementById('btn-delete')?.addEventListener('click', () => setMode('delete'));
document.addEventListener('keydown', e => {
	if (e.key === 'Escape') setMode(mode);
});

canvas.addEventListener('click', async e => {
	if (!mode) return;

	const hit = e.target.closest('.link-hit');
	const nEl = e.target.closest('.node');

	if (mode === 'delete') {
		if (hit) {
			const link = DATA.links.find(l => l.linkid === +hit.dataset.linkid);

			if (link && confirm('ลบเส้นเชื่อมนี้?')) {
				const r = await post({mode: 'delete_link', linkid: link.linkid});

				if (r && r.ok) location.reload();
			}
		}
		else if (nEl) {
			const n = nodeById(+nEl.dataset.id);

			if (n && confirm('ลบ "' + n.name + '"' +
					(n.type === 'group' ? ' (อุปกรณ์ในกลุ่มจะถูกปล่อยออก ไม่ถูกลบ)' : '') + ' ?')) {
				const r = await post({mode: 'delete_node', nodeid: n.nodeid});

				if (r && r.ok) location.reload();
			}
		}

		return;
	}

	if (mode === 'link' && nEl) {
		const id = +nEl.dataset.id;

		if (linkSrc === null) {
			linkSrc = id;
			nEl.classList.add('is-src');
			toast('เลือกต้นทางแล้ว — คลิกโหนดปลายทาง');
		}
		else if (linkSrc !== id) {
			const r = await post({mode: 'add_link', a: linkSrc, b: id});

			if (r && r.ok) location.reload();
		}
	}
});

// ---- add forms ----

const openPanel = id => {
	document.querySelectorAll('.panel').forEach(p => p.classList.remove('is-open'));
	document.getElementById(id)?.classList.add('is-open');
	document.getElementById(id)?.querySelector('input,select')?.focus();
};

const closePanels = () => document.querySelectorAll('.panel').forEach(p => p.classList.remove('is-open'));

document.getElementById('btn-add-group')?.addEventListener('click', () => openPanel('panel-group'));
document.getElementById('btn-add-device')?.addEventListener('click', () => openPanel('panel-device'));
document.getElementById('btn-cancel-group')?.addEventListener('click', closePanels);
document.getElementById('btn-cancel-device')?.addEventListener('click', closePanels);

function viewportCenter() {
	return {
		x: Math.round(viewport.scrollLeft + viewport.clientWidth / 2 - NODE_W / 2 + (Math.random() * 120 - 60)),
		y: Math.round(viewport.scrollTop + viewport.clientHeight / 2 - NODE_H / 2 + (Math.random() * 120 - 60))
	};
}

document.getElementById('btn-save-group')?.addEventListener('click', async () => {
	const name = document.getElementById('group-name').value.trim();

	if (!name) return toast('ใส่ชื่อกลุ่มก่อน');

	const c = viewportCenter();
	const r = await post({mode: 'add_group', name, x: c.x, y: c.y});

	if (r && r.ok) location.reload();
	if (r && !r.ok) toast(r.msg);
});

document.getElementById('btn-save-device')?.addEventListener('click', async () => {
	const name = document.getElementById('dev-name').value.trim();

	if (!name) return toast('ใส่ชื่ออุปกรณ์ก่อน');

	const c = viewportCenter();
	const r = await post({
		mode: 'add_device', name,
		hostid: document.getElementById('dev-host').value,
		groupid: document.getElementById('dev-group').value,
		x: c.x, y: c.y
	});

	if (r && r.ok) location.reload();
	if (r && !r.ok) toast(r.msg);
});

// ---- plumbing ----

async function post(body, silent) {
	const fd = new FormData();
	fd.append('ajax', '1');
	fd.append('csrf_token', DATA.csrf_token);

	for (const [k, v] of Object.entries(body)) {
		fd.append(k, v);
	}

	try {
		const res = await fetch('topo_tree.php', {method: 'POST', body: fd});
		return await res.json();
	}
	catch (e) {
		if (!silent) toast('การเชื่อมต่อขัดข้อง ลองใหม่อีกครั้ง');
		return null;
	}
}

let toastTimer;

function toast(msg) {
	const el = document.getElementById('toast');
	el.textContent = msg;
	el.classList.add('is-on');
	clearTimeout(toastTimer);
	toastTimer = setTimeout(() => el.classList.remove('is-on'), 3500);
}

// auto-refresh problem statuses every 30 s
setInterval(async () => {
	try {
		const res = await fetch('topo_tree.php?' + new URLSearchParams({ajax: '1', mode: 'status'}));
		const json = await res.json();

		if (json && json.statuses) {
			for (const [k, v] of Object.entries(json.statuses)) {
				statuses.set(+k, v);
			}

			updateGeometry();
			applyStatuses();
			document.getElementById('live-time').textContent =
				new Date().toLocaleTimeString('th-TH', {hour12: false});
		}
	}
	catch (e) {
		// transient network failure — keep the last known statuses
	}
}, 30000);
</script>
</body>
</html>
