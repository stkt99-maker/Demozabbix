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
	$res = DBselect('SELECT f.hostid, MAX(p.severity) AS sev, COUNT(*) AS cnt'.
			' FROM problems p JOIN functions f ON f.triggerid = p.objectid'.
			' WHERE p.r_eventid IS NULL GROUP BY f.hostid');

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

		DBexecute('INSERT INTO topo_node (type, name, posx, posy) VALUES (\'group\', '.zbx_dbstr($name).', '
			.max(0, (int) getRequest('x', 60)).', '.max(0, (int) getRequest('y', 60)).')');
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

		DBexecute('INSERT INTO topo_node (type, name, parentid, hostid, posx, posy) VALUES (\'device\', '
			.zbx_dbstr($name).', '.($groupid > 0 ? $groupid : 'NULL').', '.($hostid > 0 ? $hostid : 'NULL').', '
			.max(0, (int) getRequest('x', 120)).', '.max(0, (int) getRequest('y', 120)).')');
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
			$reply(false, 'เส้นเชื่อมนี้มีอยู่แล้ว');
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
		body { background: #ebeef0; margin: 0; font-family: Sarabun, Arial, Tahoma, sans-serif;
			color: #1f2c33; font-size: 14px; }
		.topo { display: flex; flex-direction: column; height: 100vh; }
		.topo .topbar { background: #fff; border-bottom: 1px solid #dfe4e7; padding: 10px 18px;
			display: flex; align-items: center; gap: 12px; flex-wrap: wrap; box-shadow: 0 1px 3px rgba(31,44,51,.05); }
		.topo .topbar h1 { font-size: 17px; margin: 0; margin-right: 6px; }
		.topo .topbar .spacer { flex: 1; }
		.topo .topbar .back { color: #1e87e3; text-decoration: none; font-size: 13.5px; }
		.topo .topbar .back:hover { text-decoration: underline; }
		.topo .tbtn { border: 0; border-radius: 6px; padding: 8px 16px; cursor: pointer; font-size: 13.5px;
			font-family: inherit; height: auto; line-height: 1.5; white-space: nowrap;
			display: inline-flex; align-items: center; justify-content: center;
			background: #1e87e3; color: #fff; transition: filter .15s, background-color .15s; }
		.topo .tbtn:hover { filter: brightness(.92); }
		.topo .tbtn.tbtn-ghost { background: #eef2f5; color: #1f2c33; border: 1px solid #d3dbe1; }
		.topo .tbtn.is-active { background: #0f6cb8; box-shadow: 0 0 0 2px rgba(30,135,227,.35); }
		.topo .tbtn:disabled { opacity: .45; cursor: not-allowed; }
		.topo .hint { color: #76828d; font-size: 12.5px; }
		.topo .panel { background: #fff; border-bottom: 1px solid #dfe4e7; padding: 12px 18px;
			display: none; gap: 10px; align-items: flex-end; flex-wrap: wrap; }
		.topo .panel.is-open { display: flex; }
		.topo .panel label { display: block; font-size: 12px; color: #76828d; margin-bottom: 4px; }
		.topo .panel input, .topo .panel select { font-family: inherit; font-size: 13.5px; padding: 7px 10px;
			border: 1px solid #ccd5d9; border-radius: 6px; min-width: 200px; background: #fff; color: #1f2c33; }
		.topo .legend { display: flex; gap: 10px; align-items: center; font-size: 11.5px; color: #76828d;
			padding: 6px 18px; background: #f6f8f9; border-bottom: 1px solid #e8ecef; flex-wrap: wrap; }
		.topo .legend .dot { width: 9px; height: 9px; border-radius: 50%; display: inline-block;
			margin-right: 4px; vertical-align: middle; }
		.topo .viewport { flex: 1; overflow: auto; position: relative; }
		.topo .canvas { position: relative; background-image: radial-gradient(#d5dce2 1px, transparent 1px);
			background-size: 24px 24px; }
		.topo .canvas.is-linkmode { cursor: crosshair; }
		.topo .canvas.is-delmode { cursor: not-allowed; }
		.topo svg.links { position: absolute; inset: 0; pointer-events: none; }
		.topo svg.links .link-hit { pointer-events: stroke; stroke: transparent; stroke-width: 14;
			cursor: pointer; }
		.topo .gbox { position: absolute; border: 1.5px dashed #9db8cb; border-radius: 14px;
			background: rgba(214, 232, 245, .18); z-index: 1; }
		.topo .gbox .glabel { position: absolute; top: -11px; left: 14px; background: #e8f1f8;
			color: #2b6a9e; font-size: 12px; font-weight: bold; padding: 2px 10px; border-radius: 10px;
			border: 1px solid #bcd4e4; white-space: nowrap; }
		.topo .gbox.is-empty { border: 1.5px dashed #b3c5d2; background: transparent; cursor: move; }
		.topo .gbox.is-empty .glabel { border-style: dashed; }
		.topo .node { position: absolute; z-index: 3; background: #fff; border: 1px solid #d3dbe1;
			border-radius: 10px; box-shadow: 0 2px 6px rgba(31,44,51,.10); width: 170px; height: 56px;
			padding: 8px 10px 8px 12px; box-sizing: border-box; }
		.topo .node.is-dev { cursor: move; }
		.topo .node .n-head { display: flex; align-items: center; gap: 7px; }
		.topo .node .dot { width: 11px; height: 11px; border-radius: 50%; flex: 0 0 auto;
			background: #4caf50; box-shadow: 0 0 0 3px rgba(76,175,80,.15); }
		.topo .node .n-name { font-weight: bold; font-size: 13px; white-space: nowrap; overflow: hidden;
			text-overflow: ellipsis; }
		.topo .node .n-sub { font-size: 11px; color: #8d99a3; margin-top: 3px; white-space: nowrap;
			overflow: hidden; text-overflow: ellipsis; padding-left: 18px; }
		.topo .node .n-badge { margin-left: auto; background: #e45959; color: #fff; font-size: 10.5px;
			border-radius: 9px; padding: 1px 7px; flex: 0 0 auto; }
		.topo .node.is-src { outline: 3px solid #1e87e3; outline-offset: 2px; }
		.topo .node.is-dele { outline: 3px solid #e45959; outline-offset: 2px; }
		.topo .node.is-chip { width: 130px; height: 40px; display: flex; align-items: center; gap: 8px;
			cursor: move; }
		.topo .node.is-chip .n-sub { display: none; }
		.topo .toast { position: fixed; bottom: 22px; left: 50%; transform: translateX(-50%);
			background: #2b3a44; color: #fff; padding: 10px 18px; border-radius: 8px; font-size: 13.5px;
			box-shadow: 0 4px 14px rgba(0,0,0,.25); opacity: 0; transition: opacity .25s; z-index: 50;
			pointer-events: none; max-width: 80vw; }
		.topo .toast.is-on { opacity: 1; }
	</style>
</head>
<body>
<div class="topo">
	<div class="topbar">
		<h1>แผนผังเครือข่าย (Hybrid Topology)</h1>
<?php if ($is_admin): ?>
		<button type="button" class="tbtn" id="btn-add-group">+ กลุ่ม</button>
		<button type="button" class="tbtn" id="btn-add-device">+ อุปกรณ์</button>
		<button type="button" class="tbtn tbtn-ghost" id="btn-link">โหมดเชื่อมเส้น</button>
		<button type="button" class="tbtn tbtn-ghost" id="btn-delete">โหมดลบ</button>
<?php else: ?>
		<span class="hint">ดูแบบอย่างเดียว (เฉพาะ Super admin จึงจะแก้ไขแผนผังได้)</span>
<?php endif ?>
		<span class="spacer"></span>
		<span class="hint">อัปเดตสถานะอัตโนมัติทุก 30 วินาที</span>
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

	<div class="legend" id="legend"></div>

	<div class="viewport" id="viewport">
		<div class="canvas" id="canvas">
			<svg class="links" id="svg"></svg>
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
const OK_COLOR = '#4caf50';
const NODE_W = 170, NODE_H = 56, CHIP_W = 130, CHIP_H = 40;

const canvas = document.getElementById('canvas');
const svg = document.getElementById('svg');
const viewport = document.getElementById('viewport');
const nodes = new Map(DATA.nodes.map(n => [n.nodeid, n]));
const statuses = new Map(Object.entries(DATA.statuses).map(([k, v]) => [+k, v]));

const sevColor = s => s >= 0 ? SEV[s].c : OK_COLOR;
const sevLabel = s => s >= 0 ? SEV[s].t : 'ปกติ';

// Legend
document.getElementById('legend').innerHTML =
	'<b style="font-weight:600">ระดับการแจ้งเตือน:</b> ' +
	[['ปกติ', OK_COLOR], ...SEV.map(s => [s.t, s.c])]
		.map(([t, c]) => '<span><span class="dot" style="background:'+c+'"></span>'+t+'</span>').join('');

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
		sub.className = 'n-sub';
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

const gboxEls = new Map();

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
	label.textContent = g.name;
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

		html += '<line x1="'+p1.x+'" y1="'+p1.y+'" x2="'+p2.x+'" y2="'+p2.y+'" stroke="'+color+
			'" stroke-width="'+w+'" opacity=".75"/>' +
			'<line class="link-hit" data-linkid="'+l.linkid+'" x1="'+p1.x+'" y1="'+p1.y+
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
			dot.style.boxShadow = '0 0 0 3px ' + sevColor(st.sev) + '2e';
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

document.getElementById('btn-add-group')?.addEventListener('click', () => openPanel('panel-group'));
document.getElementById('btn-add-device')?.addEventListener('click', () => openPanel('panel-device'));
document.getElementById('btn-cancel-group')?.addEventListener('click', () => closePanels());
document.getElementById('btn-cancel-device')?.addEventListener('click', () => closePanels());

function closePanels() {
	document.querySelectorAll('.panel').forEach(p => p.classList.remove('is-open'));
}

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
		}
	}
	catch (e) {
		// transient network failure — keep the last known statuses
	}
}, 30000);
</script>
</body>
</html>
