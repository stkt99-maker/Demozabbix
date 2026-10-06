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
	// DBfetch($res, false): keep real NULLs — Zabbix's default DBfetch turns NULL into '0',
	// which would make hostless/ungrouped nodes look like hostid/parentid 0 everywhere below.
	$res = DBselect('SELECT topo_nodeid AS nodeid, type, name, parentid, hostid, posx, posy FROM topo_node ORDER BY topo_nodeid');

	while ($row = DBfetch($res, false)) {
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

/**
 * Interface traffic (bps) per host, from the latest history values of
 * net.if.in / net.if.out items. Loopback excluded. items has no lastvalue
 * column in 7.4 — the newest history row per item is taken instead.
 */
function topoTraffic(array $nodes): array {
	$hostids = [];

	foreach ($nodes as $node) {
		if ($node['type'] === 'device' && $node['hostid'] !== null) {
			$hostids[$node['hostid']] = true;
		}
	}

	if (!$hostids) {
		return [];
	}

	$list = implode(',', array_map('intval', array_keys($hostids)));
	$items = [];
	$res = DBselect('SELECT itemid, hostid, key_, value_type FROM items WHERE hostid IN ('.$list.')'.
			' AND status = 0'.
			' AND (key_ LIKE \'net.if.in[%\' OR key_ LIKE \'net.if.out[%\')');

	while ($row = DBfetch($res)) {
		if (strpos($row['key_'], '["lo"]') !== false) {
			continue;
		}

		$items[(int) $row['itemid']] = [
			'hostid' => (int) $row['hostid'],
			'dir'    => strpos($row['key_'], 'net.if.in') === 0 ? 'in' : 'out',
			'vt'     => (int) $row['value_type']
		];
	}

	$by_table = ['history' => [], 'history_uint' => []];

	foreach ($items as $itemid => $item) {
		$by_table[$item['vt'] === 3 ? 'history_uint' : 'history'][] = $itemid;
	}

	$values = [];

	foreach ($by_table as $table => $itemids) {
		if (!$itemids) {
			continue;
		}

		$res = DBselect('SELECT DISTINCT ON (itemid) itemid, value FROM '.$table.
				' WHERE itemid IN ('.implode(',', $itemids).') ORDER BY itemid, clock DESC');

		while ($row = DBfetch($res)) {
			$values[(int) $row['itemid']] = (float) $row['value'];
		}
	}

	$traffic = [];

	foreach ($items as $itemid => $item) {
		if (!isset($values[$itemid])) {
			continue;
		}

		if (!isset($traffic[$item['hostid']])) {
			$traffic[$item['hostid']] = ['in' => 0.0, 'out' => 0.0];
		}

		$traffic[$item['hostid']][$item['dir']] += $values[$itemid];
	}

	return $traffic;
}

/**
 * Interface details for one host: Zabbix interface IPs plus the latest
 * net.if.in / net.if.out value per discovered interface (loopback excluded).
 */
function topoIfaces(int $hostid): array {
	$ips = [];
	$res = DBselect('SELECT ip FROM interface WHERE hostid = '.$hostid.' ORDER BY interfaceid');

	while ($row = DBfetch($res)) {
		if ($row['ip'] !== '' && !in_array($row['ip'], $ips)) {
			$ips[] = $row['ip'];
		}
	}

	$items = [];
	$res = DBselect('SELECT itemid, key_, value_type FROM items WHERE hostid = '.$hostid.
			' AND status = 0'.
			' AND (key_ LIKE \'net.if.in[%\' OR key_ LIKE \'net.if.out[%\')');

	while ($row = DBfetch($res)) {
		$l = strpos($row['key_'], '[');
		$r = strrpos($row['key_'], ']');

		if ($l === false || $r === false || $r < $l) {
			continue;
		}

		$spec = substr($row['key_'], $l + 1, $r - $l - 1);
		$comma = strpos($spec, ',');
		$ifname = trim($comma === false ? $spec : substr($spec, 0, $comma), '"\' ');
		$mode = $comma === false ? '' : strtolower(trim(substr($spec, $comma + 1), '"\' '));

		if ($ifname === '' || $ifname === 'lo') {
			continue;
		}

		// keep plain byte counters only — dropped/errors/packets items are different metrics
		if ($mode !== '' && $mode !== 'bytes') {
			continue;
		}

		$items[(int) $row['itemid']] = [
			'if'  => $ifname,
			'dir' => strpos($row['key_'], 'net.if.in') === 0 ? 'in' : 'out',
			'vt'  => (int) $row['value_type']
		];
	}

	$by_table = ['history' => [], 'history_uint' => []];

	foreach ($items as $itemid => $item) {
		$by_table[$item['vt'] === 3 ? 'history_uint' : 'history'][] = $itemid;
	}

	$values = [];

	foreach ($by_table as $table => $itemids) {
		if (!$itemids) {
			continue;
		}

		$res = DBselect('SELECT DISTINCT ON (itemid) itemid, value FROM '.$table.
				' WHERE itemid IN ('.implode(',', $itemids).') ORDER BY itemid, clock DESC');

		while ($row = DBfetch($res)) {
			$values[(int) $row['itemid']] = (float) $row['value'];
		}
	}

	$ifaces = [];

	foreach ($items as $itemid => $item) {
		if (!isset($values[$itemid])) {
			continue;
		}

		if (!isset($ifaces[$item['if']])) {
			$ifaces[$item['if']] = ['name' => $item['if'], 'in' => 0.0, 'out' => 0.0];
		}

		$ifaces[$item['if']][$item['dir']] += $values[$itemid];
	}

	$ifaces = array_values($ifaces);
	usort($ifaces, static function (array $a, array $b): int {
		return ($b['in'] + $b['out']) <=> ($a['in'] + $a['out']);
	});

	return ['ips' => $ips, 'ifaces' => $ifaces];
}

/**
 * The local Zabbix server's own host (it always has a 127.0.0.1 interface).
 */
function topoServerHostid(): ?int {
	$row = DBfetch(DBselect('SELECT hostid FROM interface WHERE ip = \'127.0.0.1\' LIMIT 1'));

	return $row !== false ? (int) $row['hostid'] : null;
}

/**
 * hostid => list of IPv4 addresses configured as Zabbix interfaces.
 */
function topoHostIps(array $hostids): array {
	$ips = [];

	if ($hostids) {
		$list = implode(',', array_map('intval', array_keys($hostids)));
		$res = DBselect('SELECT hostid, ip FROM interface WHERE hostid IN ('.$list.')');

		while ($row = DBfetch($res)) {
			$ips[(int) $row['hostid']][] = $row['ip'];
		}
	}

	return $ips;
}

/**
 * True when any IPv4 pair shares a /24. IPv6 and unparseable values are ignored.
 */
function topoSameSubnet24(array $ips_a, array $ips_b): bool {
	foreach ($ips_a as $ipa) {
		$na = ip2long($ipa);

		if ($na === false) {
			continue;
		}

		foreach ($ips_b as $ipb) {
			$nb = ip2long($ipb);

			if ($nb !== false && ($na >> 8) === ($nb >> 8)) {
				return true;
			}
		}
	}

	return false;
}

/**
 * Pairs (a < b) that the relationship rules say should be linked: star around the
 * Zabbix server node, and /24-subnet mesh between devices. Run only on demand
 * (the Auto-link button) — adding or editing devices never links by itself.
 */
function topoAutoLinkPairs(array $nodes): array {
	$devices = [];
	$server_hostid = topoServerHostid();
	$server_nodeid = null;

	foreach ($nodes as $node) {
		if ($node['type'] !== 'device' || $node['hostid'] === null) {
			continue;
		}

		$devices[$node['nodeid']] = $node;

		if ($server_hostid !== null && $node['hostid'] === $server_hostid) {
			$server_nodeid = $node['nodeid'];
		}
	}

	if (!$devices) {
		return [];
	}

	$host_ips = topoHostIps(array_flip(array_map(static function ($n) {
		return $n['hostid'];
	}, $devices)));

	$existing = [];
	$res = DBselect('SELECT nodeida, nodeidb FROM topo_link');

	while ($row = DBfetch($res)) {
		$a = (int) $row['nodeida'];
		$b = (int) $row['nodeidb'];
		$existing[min($a, $b).':'.max($a, $b)] = true;
	}

	$ids = array_keys($devices);
	$pairs = [];

	foreach ($ids as $t) {
		if ($server_nodeid !== null && $server_nodeid !== $t) {
			$key = min($t, $server_nodeid).':'.max($t, $server_nodeid);

			if (!isset($existing[$key])) {
				$existing[$key] = true;
				$pairs[] = [min($t, $server_nodeid), max($t, $server_nodeid)];
			}
		}

		foreach ($ids as $o) {
			if ($o === $t) {
				continue;
			}

			$key = min($t, $o).':'.max($t, $o);

			if (isset($existing[$key])) {
				continue;
			}

			if (topoSameSubnet24($host_ips[$devices[$t]['hostid']] ?? [],
					$host_ips[$devices[$o]['hostid']] ?? [])) {
				$existing[$key] = true;
				$pairs[] = [min($t, $o), max($t, $o)];
			}
		}
	}

	return $pairs;
}

$ajax = (getRequest('ajax') === '1');

// Read-only status feed for the auto-refresh poller.
if ($ajax && getRequest('mode') === 'status') {
	[$nodes] = topoLoad();
	session_write_close();

	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode([
		'statuses' => topoStatuses($nodes),
		'traffic' => topoTraffic($nodes)
	], JSON_UNESCAPED_UNICODE);
	exit;
}

// Read-only per-device details for the click-to-inspect card: the device's own
// interfaces plus, for every topology link, the neighbor node and its IPs.
if ($ajax && getRequest('mode') === 'ifaces') {
	[$nodes, $links] = topoLoad();
	session_write_close();

	header('Content-Type: application/json; charset=UTF-8');

	$nodeid = (int) getRequest('nodeid', 0);
	$node = $nodes[$nodeid] ?? null;

	if ($node === null || $node['type'] !== 'device') {
		echo json_encode(['ok' => false, 'msg' => _('The selected node was not found.')],
			JSON_UNESCAPED_UNICODE);
		exit;
	}

	$neighbor_ids = [];

	foreach ($links as $l) {
		if ($l['a'] === $nodeid) {
			$neighbor_ids[] = $l['b'];
		}
		elseif ($l['b'] === $nodeid) {
			$neighbor_ids[] = $l['a'];
		}
	}

	$neighbors = [];
	$host_to_node = [];

	foreach (array_unique($neighbor_ids) as $nid) {
		$n = $nodes[$nid] ?? null;

		if ($n === null) {
			continue;
		}

		$neighbors[$nid] = ['nodeid' => $nid, 'name' => $n['name'], 'ips' => []];

		if ($n['hostid'] !== null) {
			$host_to_node[$n['hostid']] = $nid;
		}
	}

	if ($host_to_node) {
		$host_ips = topoHostIps($host_to_node);

		foreach ($host_to_node as $hid => $nid) {
			$neighbors[$nid]['ips'] = $host_ips[$hid] ?? [];
		}
	}

	$out = ['ok' => true, 'links' => array_values($neighbors)]
		+ ($node['hostid'] !== null
			? topoIfaces($node['hostid'])
			: ['ips' => [], 'ifaces' => []]);

	echo json_encode($out, JSON_UNESCAPED_UNICODE);
	exit;
}

// Write actions, Super admin only, CSRF protected, JSON answers.
if ($ajax) {
	session_write_close();
	header('Content-Type: application/json; charset=UTF-8');

	$reply = static function (bool $ok, string $msg = '', array $extra = []): void {
		echo json_encode(['ok' => $ok, 'msg' => $msg] + $extra, JSON_UNESCAPED_UNICODE);
		exit;
	};

	if (!$is_admin) {
		$reply(false, _('Only Super admins can edit the topology.'));
	}

	if (!CCsrfTokenHelper::check(getRequest('csrf_token', ''), 'topo_tree.php')) {
		$reply(false, _('Invalid CSRF token — refresh the page and try again.'));
	}

	$mode = getRequest('mode', '');

	if ($mode === 'add_group') {
		$name = trim(getRequest('name', ''));

		if ($name === '' || mb_strlen($name) > 64) {
			$reply(false, _('Group name must be 1-64 characters.'));
		}

		if (!DBexecute('INSERT INTO topo_node (type, name, posx, posy) VALUES (\'group\', '.zbx_dbstr($name).', '
			.max(0, (int) getRequest('x', 60)).', '.max(0, (int) getRequest('y', 60)).')')) {
			$reply(false, _('Database write failed (check the DB user privileges).'));
		}

		$reply(true, _('Group added.'));
	}

	if ($mode === 'add_device') {
		$name = trim(getRequest('name', ''));
		$hostid = (int) getRequest('hostid', 0);
		$groupid = (int) getRequest('groupid', 0);

		if ($name === '' || mb_strlen($name) > 64) {
			$reply(false, _('Device name must be 1-64 characters.'));
		}

		if ($hostid > 0 && !API::Host()->get(['hostids' => $hostid, 'filter' => ['status' => HOST_STATUS_MONITORED],
				'countOutput' => true])) {
			$reply(false, _('The selected host was not found in Zabbix.'));
		}

		if ($groupid > 0) {
			$group = DBfetch(DBselect('SELECT topo_nodeid FROM topo_node WHERE topo_nodeid = '.$groupid.
					' AND type = \'group\''));

			if ($group === false) {
				$reply(false, _('The selected group was not found.'));
			}
		}

		$newid = DBfetch(DBselect('INSERT INTO topo_node (type, name, parentid, hostid, posx, posy) VALUES (\'device\', '
			.zbx_dbstr($name).', '.($groupid > 0 ? $groupid : 'NULL').', '.($hostid > 0 ? $hostid : 'NULL').', '
			.max(0, (int) getRequest('x', 120)).', '.max(0, (int) getRequest('y', 120)).
			') RETURNING topo_nodeid'));

		if ($newid === false) {
			$reply(false, _('Database write failed (check the DB user privileges).'));
		}

		// No automatic linking — connections are drawn by hand or via the Auto-link button.
		$reply(true, _('Device added'));
	}

	if ($mode === 'update_device') {
		$nodeid = (int) getRequest('nodeid', 0);
		$name = trim(getRequest('name', ''));
		$hostid = (int) getRequest('hostid', 0);
		$groupid = (int) getRequest('groupid', 0);

		$node = DBfetch(DBselect('SELECT topo_nodeid, type FROM topo_node WHERE topo_nodeid = '.$nodeid));

		if ($node === false || $node['type'] !== 'device') {
			$reply(false, _('The selected node was not found.'));
		}

		if ($name === '' || mb_strlen($name) > 64) {
			$reply(false, _('Device name must be 1-64 characters.'));
		}

		if ($hostid > 0 && !API::Host()->get(['hostids' => $hostid, 'filter' => ['status' => HOST_STATUS_MONITORED],
				'countOutput' => true])) {
			$reply(false, _('The selected host was not found in Zabbix.'));
		}

		if ($groupid > 0) {
			$group = DBfetch(DBselect('SELECT topo_nodeid FROM topo_node WHERE topo_nodeid = '.$groupid.
					' AND type = \'group\''));

			if ($group === false) {
				$reply(false, _('The selected group was not found.'));
			}
		}

		if (!DBexecute('UPDATE topo_node SET name = '.zbx_dbstr($name).', parentid = '
				.($groupid > 0 ? $groupid : 'NULL').', hostid = '.($hostid > 0 ? $hostid : 'NULL')
				.' WHERE topo_nodeid = '.$nodeid)) {
			$reply(false, _('Database write failed (check the DB user privileges).'));
		}

		// No automatic linking here either — existing links are kept, new ones are manual.
		$reply(true, _('Device updated.'));
	}

	if ($mode === 'add_link') {
		$a = (int) getRequest('a', 0);
		$b = (int) getRequest('b', 0);

		if ($a <= 0 || $b <= 0 || $a === $b) {
			$reply(false, _('Pick valid source and destination nodes.'));
		}

		$exists = DBfetch(DBselect('SELECT n1.topo_nodeid AS a, n2.topo_nodeid AS b FROM topo_node n1'.
				' JOIN topo_node n2 ON n2.topo_nodeid = '.$b.' WHERE n1.topo_nodeid = '.$a));

		if ($exists === false) {
			$reply(false, _('The selected nodes were not found.'));
		}

		if (!DBexecute('INSERT INTO topo_link (nodeida, nodeidb) VALUES ('.$a.', '.$b.')')) {
			$reply(false, _('This link already exists, or the database write failed.'));
		}

		$reply(true, _('Link added.'));
	}

	if ($mode === 'auto_link') {
		[$nodes_all] = topoLoad();
		$added = 0;

		foreach (topoAutoLinkPairs($nodes_all) as $pair) {
			if (DBexecute('INSERT INTO topo_link (nodeida, nodeidb) VALUES ('.$pair[0].', '.$pair[1].')')) {
				$added++;
			}
		}

		$reply(true, $added > 0
			? _s('Auto-linked %1$s new link(s) (same subnet + around Zabbix server)', $added)
			: _('All detected relationships are already linked.'), ['added' => $added]);
	}

	if ($mode === 'delete_node') {
		$nodeid = (int) getRequest('nodeid', 0);

		if (!DBfetch(DBselect('SELECT topo_nodeid FROM topo_node WHERE topo_nodeid = '.$nodeid))) {
			$reply(false, _('The node to delete was not found.'));
		}

		// Ungroup members first so deleting a group keeps its devices on the canvas.
		DBexecute('UPDATE topo_node SET parentid = NULL WHERE parentid = '.$nodeid);
		DBexecute('DELETE FROM topo_node WHERE topo_nodeid = '.$nodeid);
		$reply(true, _('Node deleted.'));
	}

	if ($mode === 'delete_link') {
		$linkid = (int) getRequest('linkid', 0);

		DBexecute('DELETE FROM topo_link WHERE topo_linkid = '.$linkid);
		$reply(true, _('Link deleted.'));
	}

	if ($mode === 'move') {
		$nodeid = (int) getRequest('nodeid', 0);
		$x = max(0, (int) getRequest('x', 0));
		$y = max(0, (int) getRequest('y', 0));

		DBexecute('UPDATE topo_node SET posx = '.$x.', posy = '.$y.' WHERE topo_nodeid = '.$nodeid);
		$reply(true);
	}

	$reply(false, _('Unknown command.'));
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

// <option> lists shared by the "add device" panel and the per-device settings modal.
$host_options = '';
foreach ($hosts as $host) {
	$host_options .= '<option value="'.(int) $host['hostid'].'">'
		.htmlspecialchars($host['name'], ENT_QUOTES).'</option>';
}

$group_options = '';
foreach ($nodes as $n) {
	if ($n['type'] === 'group') {
		$group_options .= '<option value="'.$n['nodeid'].'">'
			.htmlspecialchars($n['name'], ENT_QUOTES).'</option>';
	}
}

$csrf_token = CCsrfTokenHelper::get('topo_tree.php');

// UI strings for the client-side script, translated by the user's language.
$i18n = [
	'sev_legend' => _('Severity'),
	'sev_ok' => _('Normal'),
	'sev' => [
		_('Not classified'), _('Information'), _('Warning'), _('Average'), _('High'), _('Critical')
	],
	'no_host' => _('Not bound to a host'),
	'ips_label' => _('IP addresses'),
	'no_iface_data' => _('No interface data.'),
	'settings' => _('Device settings'),
	'connections' => _('Connections'),
	'no_links' => _('No connections.'),
	'click_src_dst' => _('Click a source node, then the destination node.'),
	'click_to_delete' => _('Click a node or a link to delete it.'),
	'confirm_delete_link' => _('Delete this link?'),
	'confirm_delete_node' => _('Delete "%1$s"?'),
	'confirm_delete_group' => _('Delete "%1$s"? Devices in the group will be ungrouped, not deleted.'),
	'src_selected' => _('Source selected — click the destination node.'),
	'enter_group_name' => _('Enter a group name first.'),
	'enter_device_name' => _('Enter a device name first.'),
	'conn_failed' => _('Connection failed — try again.')
];

$data = [
	'nodes' => array_values($nodes),
	'links' => $links,
	'statuses' => $statuses,
	'traffic' => topoTraffic($nodes),
	'host_names' => $host_names,
	'is_admin' => $is_admin,
	'csrf_token' => $csrf_token
];

session_write_close();

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="<?= str_starts_with(CWebUser::$data['lang'], 'th') ? 'th' : 'en' ?>">
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
		.topo .tbtn:focus-visible, .topo .panel input:focus-visible, .topo .panel select:focus-visible,
		.topo .tmodal input:focus-visible, .topo .tmodal select:focus-visible {
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
		.topo .panel input, .topo .panel select, .topo .tmodal input, .topo .tmodal select {
			font-family: inherit; font-size: 13.5px; padding: 8px 11px;
			border: 1px solid #2a3d5e; border-radius: 8px; min-width: 210px; background-color: #0c1526;
			color: var(--text); height: auto; line-height: 1.5; }
		/* global theme forces select height 24px + white bg — reset fully and draw our own arrow */
		.topo .panel select, .topo .tmodal select { appearance: none; -webkit-appearance: none; padding-right: 30px;
			background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%238296b3' stroke-width='1.6' fill='none' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
			background-repeat: no-repeat; background-position: right 11px center; }
		.topo .panel select option, .topo .tmodal select option { background-color: #101a2e; color: var(--text); }

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
		.topo .node { position: absolute; z-index: 3; width: 170px; height: 68px; padding: 8px 10px 8px 12px;
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
		.topo .node .n-traffic { margin-top: 3px; padding-left: 18px; font-size: 10.5px; color: #6fc3ff;
			white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
		.topo .node .n-badge { margin-left: auto; background: var(--danger); color: #fff; font-size: 10.5px;
			border-radius: 9px; padding: 1px 7px; flex: 0 0 auto; }
		.topo .node.is-src { border-color: var(--accent);
			box-shadow: 0 0 0 3px var(--accent-soft), 0 0 18px rgba(63, 162, 255, .25); }
		.topo .node.is-nb { border-color: var(--accent);
			box-shadow: 0 0 0 2px var(--accent-soft), 0 0 14px rgba(63, 162, 255, .18); }
		.topo .gbox.is-nb { border-color: rgba(63, 162, 255, .75); }
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

		/* ---- interface detail card ---- */
		.topo .ifcard { position: absolute; z-index: 30; width: 270px; padding: 11px 13px;
			background: var(--surface-2); border: 1px solid var(--line); border-radius: 12px;
			box-shadow: 0 12px 32px rgba(0, 0, 0, .45); cursor: default; }
		.topo .ifcard h4 { display: flex; align-items: center; gap: 8px; margin: 0 0 1px;
			font-size: 13px; font-weight: 600; color: var(--text); }
		.topo .ifcard .ifc-sub { color: var(--text-dim); font-size: 11.5px; }
		.topo .ifcard .ifc-close { margin-left: auto; background: none; border: 0; color: var(--text-dim);
			cursor: pointer; font-size: 15px; line-height: 1; padding: 2px 6px; border-radius: 6px; }
		.topo .ifcard .ifc-close:hover { color: var(--text); background: var(--accent-soft); }
		.topo .ifcard .ifc-body { margin-top: 8px; }
		.topo .ifcard .ifc-ips { font-size: 11.5px; color: var(--text-dim); margin-bottom: 7px; }
		.topo .ifcard .ifc-ips .mono { color: #6fc3ff; }
		.topo .ifcard table { width: 100%; border-collapse: collapse; font-size: 12px; }
		.topo .ifcard td { padding: 5px 0 4px; border-top: 1px solid var(--line-soft); }
		.topo .ifcard td.ifc-name { color: var(--text); font-weight: 600; }
		.topo .ifcard td.ifc-rate { text-align: right; color: var(--text-dim); white-space: nowrap; }
		.topo .ifcard td.ifc-rate .mono { color: #6fc3ff; }
		.topo .ifcard .ifc-note { color: var(--text-dim); font-size: 12px; }
		.topo .ifcard .ifc-sec { margin-top: 10px; padding-top: 8px; border-top: 1px solid var(--line-soft);
			font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .07em;
			color: var(--text-dim); }
		.topo .ifcard .ifc-conn { padding: 5px 0 1px; font-size: 12px; }
		.topo .ifcard .ifc-conn-name { color: var(--text); font-weight: 600; }
		.topo .ifcard .ifc-conn-ips { color: #6fc3ff; font-size: 11px; margin-top: 1px; word-break: break-all; }
		.topo .ifcard .ifc-gear { margin-left: auto; background: none; border: 0; color: var(--text-dim);
			cursor: pointer; font-size: 14px; line-height: 1; padding: 2px 6px; border-radius: 6px; }
		.topo .ifcard .ifc-gear:hover { color: var(--accent); background: var(--accent-soft); }
		.topo .ifcard .ifc-gear + .ifc-close { margin-left: 0; }

		/* ---- device settings modal ---- */
		.topo .tmodal-wrap { position: fixed; inset: 0; z-index: 60; display: none; place-items: center;
			background: rgba(4, 9, 18, .62); backdrop-filter: blur(3px); }
		.topo .tmodal-wrap.is-on { display: grid; }
		.topo .tmodal { width: min(430px, calc(100vw - 32px)); box-sizing: border-box; background: var(--surface-2);
			border: 1px solid var(--line); border-radius: 14px; padding: 16px 18px 18px;
			box-shadow: 0 18px 48px rgba(0, 0, 0, .5); }
		.topo .tmodal h3 { margin: 0; font-size: 15px; }
		.topo .tmodal label { display: block; font-size: 12px; color: var(--text-dim); margin: 13px 0 5px; }
		.topo .tmodal input, .topo .tmodal select { width: 100%; box-sizing: border-box; }
		.topo .tmodal .tmodal-btns { display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px; }

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
			<h1><?= _('Network topology (Hybrid)') ?></h1>
			<div class="sub"><?= _('Arrange and connect nodes freely. Status follows real host problem severity.') ?></div>
		</div>
		<span class="spacer"></span>
		<span class="live"><span class="live-dot"></span><?= _('LIVE') ?> <span class="mono" id="live-time"></span></span>
<?php if ($is_admin): ?>
		<button type="button" class="tbtn" id="btn-add-group"><?= _('+ Group') ?></button>
		<button type="button" class="tbtn" id="btn-add-device"><?= _('+ Device') ?></button>
		<button type="button" class="tbtn tbtn-ghost" id="btn-autolink" title="<?= _('Link by relationship: same subnets + a star around the Zabbix server.') ?>"><?= _('Auto-link') ?></button>
		<button type="button" class="tbtn tbtn-ghost" id="btn-link"><?= _('Link mode') ?></button>
		<button type="button" class="tbtn tbtn-ghost is-danger" id="btn-delete"><?= _('Delete mode') ?></button>
<?php else: ?>
		<span class="sub" style="padding: 4px 10px; border: 1px solid var(--line-soft); border-radius: 8px;">
			<?= _('View only — only Super admins can edit.') ?></span>
<?php endif ?>
		<a class="back" href="zabbix.php"><?= _('← Back to main menu') ?></a>
	</div>

<?php if ($is_admin): ?>
	<div class="panel" id="panel-group">
		<div>
			<label for="group-name"><?= _('Group name') ?></label>
			<input type="text" id="group-name" maxlength="64" placeholder="<?= _('e.g. Headquarters, Server Room') ?>">
		</div>
		<button type="button" class="tbtn" id="btn-save-group"><?= _('Save') ?></button>
		<button type="button" class="tbtn tbtn-ghost" id="btn-cancel-group"><?= _('Cancel') ?></button>
	</div>
	<div class="panel" id="panel-device">
		<div>
			<label for="dev-name"><?= _('Display name') ?></label>
			<input type="text" id="dev-name" maxlength="64" placeholder="<?= _('e.g. Core Switch, HR-PC-01') ?>">
		</div>
		<div>
			<label for="dev-host"><?= _('Bind to a Zabbix host (shows alert status)') ?></label>
			<select id="dev-host">
				<option value=""><?= _('— Not bound to a host —') ?></option>
				<?= $host_options ?>
			</select>
		</div>
		<div>
			<label for="dev-group"><?= _('Group membership') ?></label>
			<select id="dev-group">
				<option value=""><?= _('— No group —') ?></option>
				<?= $group_options ?>
			</select>
		</div>
		<button type="button" class="tbtn" id="btn-save-device"><?= _('Save') ?></button>
		<button type="button" class="tbtn tbtn-ghost" id="btn-cancel-device"><?= _('Cancel') ?></button>
	</div>
	<div class="tmodal-wrap" id="modal-settings">
		<div class="tmodal" role="dialog" aria-modal="true">
			<h3><?= _('Device settings') ?></h3>
			<label for="set-name"><?= _('Display name') ?></label>
			<input type="text" id="set-name" maxlength="64" placeholder="<?= _('e.g. Core Switch, HR-PC-01') ?>">
			<label for="set-host"><?= _('Bind to a Zabbix host (shows alert status)') ?></label>
			<select id="set-host">
				<option value=""><?= _('— Not bound to a host —') ?></option>
				<?= $host_options ?>
			</select>
			<label for="set-group"><?= _('Group membership') ?></label>
			<select id="set-group">
				<option value=""><?= _('— No group —') ?></option>
				<?= $group_options ?>
			</select>
			<div class="tmodal-btns">
				<button type="button" class="tbtn tbtn-ghost" id="btn-cancel-settings"><?= _('Cancel') ?></button>
				<button type="button" class="tbtn" id="btn-save-settings"><?= _('Save') ?></button>
			</div>
		</div>
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
				<strong><?= _('The canvas is empty') ?></strong>
				<?= _('Start with the «+ Group» or «+ Device» buttons above, then drag them anywhere.') ?>
			</div>
		</div>
	</div>
</div>

<div class="toast" id="toast"></div>

<script>
const DATA = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS
	| JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const T = <?= json_encode($i18n, JSON_UNESCAPED_UNICODE) ?>;

const SEV = [
	{c: '#97AAB3', t: T.sev[0]},
	{c: '#7499FF', t: T.sev[1]},
	{c: '#FFC859', t: T.sev[2]},
	{c: '#FFA059', t: T.sev[3]},
	{c: '#E97659', t: T.sev[4]},
	{c: '#E45959', t: T.sev[5]}
];
const OK_COLOR = '#38d17e';
const NODE_W = 170, NODE_H = 68, CHIP_W = 130, CHIP_H = 40;
const MOTION = matchMedia('(prefers-reduced-motion: no-preference)').matches;

const canvas = document.getElementById('canvas');
const svg = document.getElementById('svg');
const viewport = document.getElementById('viewport');
const nodes = new Map(DATA.nodes.map(n => [n.nodeid, n]));
const statuses = new Map(Object.entries(DATA.statuses).map(([k, v]) => [+k, v]));
const traffic = new Map(Object.entries(DATA.traffic || {}).map(([k, v]) => [+k, v]));
// device currently clicked (drives link highlighting); declared early because
// drawLinks runs during the first render, before the ifcard section appears
let selectedId = null;

const sevColor = s => s >= 0 ? SEV[s].c : OK_COLOR;

function fmtBps(v) {
	if (!isFinite(v) || v <= 0) return '0 bps';

	const units = ['bps', 'Kbps', 'Mbps', 'Gbps', 'Tbps'];
	let u = 0;

	while (v >= 1000 && u < units.length - 1) {
		v /= 1000;
		u++;
	}

	return v.toFixed(v < 10 && u > 0 ? 1 : 0) + ' ' + units[u];
}

// HUD legend
document.getElementById('hud').innerHTML =
	'<b>' + T.sev_legend + '</b>' +
	[[T.sev_ok, OK_COLOR], ...SEV.map(s => [s.t, s.c])]
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
		const host = n.hostid && DATA.host_names[n.hostid] ? DATA.host_names[n.hostid] : T.no_host;
		sub.textContent = host;

		const tr = document.createElement('div');
		tr.className = 'n-traffic mono';

		el.append(head, sub, tr);
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

		// when a device is selected its own links stand out and the rest fade
		const mine = selectedId !== null && (l.a === selectedId || l.b === selectedId);
		const glow = selectedId === null ? .14 : mine ? .4 : .04;
		const alpha = selectedId === null ? .8 : mine ? 1 : .15;
		const lw = mine ? w + 1.6 : w;

		// soft under-glow, then the line itself, then packets moving both ways
		html += '<line x1="'+p1.x+'" y1="'+p1.y+'" x2="'+p2.x+'" y2="'+p2.y+'" stroke="'+color+
			'" stroke-width="'+(lw + 6)+'" opacity="'+glow+'"/>' +
			'<line x1="'+p1.x+'" y1="'+p1.y+'" x2="'+p2.x+'" y2="'+p2.y+'" stroke="'+color+
			'" stroke-width="'+lw+'" opacity="'+alpha+'"/>';

		if (MOTION && len > 60 && (selectedId === null || mine)) {
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

function applyTraffic() {
	for (const n of nodes.values()) {
		if (n.type !== 'device') continue;

		const el = nodeEls.get(n.nodeid);
		const row = el ? el.querySelector('.n-traffic') : null;

		if (!row) continue;

		const t = n.hostid !== null ? traffic.get(n.hostid) : null;

		if (t) {
			row.style.display = '';
			row.textContent = '↓ ' + fmtBps(t.in) + '  ↑ ' + fmtBps(t.out);
		}
		else {
			row.style.display = 'none';
		}
	}
}

// ring the nodes the selected device is wired to
function applySelection() {
	for (const n of nodes.values()) {
		const isNb = selectedId !== null && n.nodeid !== selectedId
			&& DATA.links.some(l => (l.a === selectedId && l.b === n.nodeid)
				|| (l.b === selectedId && l.a === n.nodeid));
		const el = n.type === 'device'
			? nodeEls.get(n.nodeid)
			: (gboxEls[n.nodeid] || nodeEls.get(n.nodeid));

		el?.classList.toggle('is-nb', isNb);
	}
}

applyTraffic();
document.getElementById('live-time').textContent = new Date().toLocaleTimeString('th-TH', {hour12: false});

// ---- interface detail card (click a device) ----

let ifcard = null, ifcardFor = null, suppressClick = false;

function closeIfcard() {
	if (ifcard) {
		ifcard.remove();
		ifcard = null;
		ifcardFor = null;
	}

	selectedId = null;
	applySelection();
	updateGeometry();
}

async function openIfcard(n) {
	if (ifcardFor === n.nodeid) {
		closeIfcard();
		return;
	}

	closeIfcard();

	const card = document.createElement('div');
	card.className = 'ifcard';

	let x = n.posx + NODE_W + 12;

	if (x + 280 > canvas.scrollWidth) {
		x = Math.max(8, n.posx - 284);
	}

	card.style.left = x + 'px';
	card.style.top = Math.max(8, n.posy) + 'px';

	const st = statuses.get(n.nodeid) || {sev: -1};
	const head = document.createElement('h4');
	const dot = document.createElement('span');
	dot.className = 'dot';
	dot.style.background = sevColor(st.sev);
	const title = document.createElement('span');
	title.textContent = n.name;
	const close = document.createElement('button');
	close.type = 'button';
	close.className = 'ifc-close';
	close.textContent = '✕';
	close.addEventListener('click', closeIfcard);
	head.append(dot, title);

	if (DATA.is_admin) {
		const gear = document.createElement('button');
		gear.type = 'button';
		gear.className = 'ifc-gear';
		gear.title = T.settings;
		gear.textContent = '⚙';
		gear.addEventListener('click', () => openSettings(n));
		head.append(gear);
	}

	head.append(close);

	const sub = document.createElement('div');
	sub.className = 'ifc-sub';
	sub.textContent = n.hostid !== null && DATA.host_names[n.hostid] ? DATA.host_names[n.hostid] : T.no_host;

	const body = document.createElement('div');
	body.className = 'ifc-body';
	body.textContent = '…';

	card.append(head, sub, body);
	canvas.appendChild(card);
	ifcard = card;
	ifcardFor = n.nodeid;
	selectedId = n.nodeid;
	applySelection();
	updateGeometry();

	const r = await post({mode: 'ifaces', nodeid: n.nodeid}, true);

	if (ifcard !== card) return; // closed or replaced while loading

	if (!r || !r.ok) {
		body.textContent = r && r.msg ? r.msg : T.conn_failed;
		return;
	}

	body.textContent = '';

	if (n.hostid === null) {
		const note = document.createElement('div');
		note.className = 'ifc-note';
		note.textContent = T.no_host;
		body.appendChild(note);
	}

	if (n.hostid !== null && r.ips.length) {
		const ips = document.createElement('div');
		ips.className = 'ifc-ips';
		ips.append(T.ips_label + ': ');

		r.ips.forEach((ip, i) => {
			if (i) ips.append(', ');

			const m = document.createElement('span');
			m.className = 'mono';
			m.textContent = ip;
			ips.append(m);
		});

		body.appendChild(ips);
	}

	// what this node is wired to (topology links), with the neighbor's IPs — shown first
	if (r.links && r.links.length) {
		const sec = document.createElement('div');
		sec.className = 'ifc-sec';
		sec.textContent = T.connections;
		body.appendChild(sec);

		for (const nb of r.links) {
			const conn = document.createElement('div');
			conn.className = 'ifc-conn';
			const nm = document.createElement('div');
			nm.className = 'ifc-conn-name';
			nm.textContent = nb.name;
			conn.appendChild(nm);

			if (nb.ips && nb.ips.length) {
				const im = document.createElement('div');
				im.className = 'ifc-conn-ips mono';
				im.textContent = nb.ips.join(', ');
				conn.appendChild(im);
			}

			body.appendChild(conn);
		}
	}
	else {
		const note = document.createElement('div');
		note.className = 'ifc-note';
		note.textContent = T.no_links;
		body.appendChild(note);
	}

	if (!r.ifaces.length) {
		const note = document.createElement('div');
		note.className = 'ifc-note';
		note.textContent = T.no_iface_data;
		body.appendChild(note);
	}

	if (r.ifaces.length) {
		const tbl = document.createElement('table');

		for (const f of r.ifaces) {
			const tr = document.createElement('tr');
			const td1 = document.createElement('td');
			td1.className = 'ifc-name';
			td1.textContent = f.name;
			const td2 = document.createElement('td');
			td2.className = 'ifc-rate mono';
			td2.textContent = '↓ ' + fmtBps(+f.in) + '  ↑ ' + fmtBps(+f.out);
			tr.append(td1, td2);
			tbl.appendChild(tr);
		}

		body.appendChild(tbl);
	}
}

// ---- device settings modal (admin) ----

let settingsFor = null;

function openSettings(n) {
	settingsFor = n.nodeid;
	document.getElementById('set-name').value = n.name;
	document.getElementById('set-host').value = n.hostid !== null ? String(n.hostid) : '';
	document.getElementById('set-group').value = n.parentid !== null ? String(n.parentid) : '';
	document.getElementById('modal-settings').classList.add('is-on');
	document.getElementById('set-name').focus();
}

function closeSettings() {
	document.getElementById('modal-settings')?.classList.remove('is-on');
	settingsFor = null;
}

document.getElementById('btn-save-settings')?.addEventListener('click', async () => {
	const name = document.getElementById('set-name').value.trim();

	if (!name) return toast(T.enter_device_name);

	const r = await post({
		mode: 'update_device', nodeid: settingsFor, name,
		hostid: document.getElementById('set-host').value,
		groupid: document.getElementById('set-group').value
	});

	if (r && r.ok) location.reload();
	if (r && !r.ok) toast(r.msg);
});

document.getElementById('btn-cancel-settings')?.addEventListener('click', closeSettings);

document.getElementById('modal-settings')?.addEventListener('click', e => {
	if (e.target === e.currentTarget) closeSettings();
});

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

		// the click event still fires after a drag — swallow it so it doesn't open the card
		suppressClick = true;
		setTimeout(() => suppressClick = false, 0);
	}

	drag = null;
});

// ---- modes: link / delete ----

let mode = null, linkSrc = null;

function setMode(next) {
	mode = mode === next ? null : next;
	linkSrc = null;
	closeIfcard();

	document.getElementById('btn-link')?.classList.toggle('is-active', mode === 'link');
	document.getElementById('btn-delete')?.classList.toggle('is-active', mode === 'delete');
	canvas.classList.toggle('is-linkmode', mode === 'link');
	canvas.classList.toggle('is-delmode', mode === 'delete');

	document.querySelectorAll('.node.is-src').forEach(el => el.classList.remove('is-src'));

	if (mode) toast(mode === 'link' ? T.click_src_dst : T.click_to_delete);
}

document.getElementById('btn-link')?.addEventListener('click', () => setMode('link'));
document.getElementById('btn-delete')?.addEventListener('click', () => setMode('delete'));
document.addEventListener('keydown', e => {
	if (e.key === 'Escape') {
		closeSettings();
		closeIfcard();
		setMode(mode);
	}
});

canvas.addEventListener('click', async e => {
	if (suppressClick || e.target.closest('.ifcard')) return;

	if (!mode) {
		const nEl = e.target.closest('.node');
		const n = nEl && nEl.classList.contains('is-dev') ? nodeById(+nEl.dataset.id) : null;

		if (n) {
			await openIfcard(n);
		}
		else {
			closeIfcard();
		}

		return;
	}

	closeIfcard();

	const hit = e.target.closest('.link-hit');
	const nEl = e.target.closest('.node');

	if (mode === 'delete') {
		if (hit) {
			const link = DATA.links.find(l => l.linkid === +hit.dataset.linkid);

			if (link && confirm(T.confirm_delete_link)) {
				const r = await post({mode: 'delete_link', linkid: link.linkid});

				if (r && r.ok) location.reload();
			}
		}
		else if (nEl) {
			const n = nodeById(+nEl.dataset.id);

			if (n && confirm((n.type === 'group'
					? T.confirm_delete_group
					: T.confirm_delete_node).replace('%1$s', n.name))) {
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
			toast(T.src_selected);
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

document.getElementById('btn-autolink')?.addEventListener('click', async () => {
	const r = await post({mode: 'auto_link'});

	if (r) {
		toast(r.msg);

		if (r.ok && r.added > 0) {
			setTimeout(() => location.reload(), 600);
		}
	}
});

function viewportCenter() {
	return {
		x: Math.round(viewport.scrollLeft + viewport.clientWidth / 2 - NODE_W / 2 + (Math.random() * 120 - 60)),
		y: Math.round(viewport.scrollTop + viewport.clientHeight / 2 - NODE_H / 2 + (Math.random() * 120 - 60))
	};
}

document.getElementById('btn-save-group')?.addEventListener('click', async () => {
	const name = document.getElementById('group-name').value.trim();

	if (!name) return toast(T.enter_group_name);

	const c = viewportCenter();
	const r = await post({mode: 'add_group', name, x: c.x, y: c.y});

	if (r && r.ok) location.reload();
	if (r && !r.ok) toast(r.msg);
});

document.getElementById('btn-save-device')?.addEventListener('click', async () => {
	const name = document.getElementById('dev-name').value.trim();

	if (!name) return toast(T.enter_device_name);

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
		if (!silent) toast(T.conn_failed);
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

			if (json.traffic) {
				for (const [k, v] of Object.entries(json.traffic)) {
					traffic.set(+k, v);
				}
			}

			updateGeometry();
			applyStatuses();
			applyTraffic();
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
