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

$page['file'] = 'mon_dashboard.php';

if (!CWebUser::isLoggedIn() || CWebUser::isGuest()) {
	redirect('index.php');
}

const MON_WINDOWS = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800];
// chart bucket size per window; all divide the window evenly so bars align
const MON_GBUCKET = ['1h' => 300, '6h' => 300, '24h' => 1800, '7d' => 10800];

const MON_TOP_PROBLEMS = 12;	// rows shown in the Current problems card
const MON_TOP_TABLES = 8;		// rows per summary table

/*
** Monitoring summary — every number on the page is read live from the Zabbix
** database (hosts, interfaces, items, triggers, problem, events); nothing is
** synthesised. Problem→host mapping follows the topo_tree.php pattern:
** problem.objectid is the triggerid, functions maps it to items → hosts.
*/

/**
 * Per-host agent-interface availability, rolled up with the same worst-case
 * rule the stock frontend uses (include/hosts.inc.php): mixed > unavailable >
 * unknown > available. Returns [available, unavailable, unknown, agentless,
 * with_agent, monitored] counts for monitored hosts; agentless = monitored
 * hosts without any agent (type 1) interface. Active-agent checks
 * (host_rtdata) are not folded in.
 */
function monAvailability(): array {
	$monitored = DBfetch(DBselect(
		'SELECT COUNT(*) AS cnt FROM hosts WHERE status='.HOST_STATUS_MONITORED.' AND flags<>1'
	));
	$monitored = (int) $monitored['cnt'];

	$res = DBselect(
		'SELECT i.hostid, MAX(CASE i.available WHEN 1 THEN 0 WHEN 0 THEN 1 WHEN 2 THEN 2'.
		' ELSE 3 END) AS worst'.
		' FROM interface i'.
		' JOIN hosts h ON h.hostid=i.hostid AND h.status='.HOST_STATUS_MONITORED.
		' WHERE i.type=1'.
		' GROUP BY i.hostid'
	);

	$counts = ['avail' => 0, 'unavail' => 0, 'unknown' => 0];
	$with_agent = 0;

	while ($row = DBfetch($res)) {
		$with_agent++;

		switch ((int) $row['worst']) {
			case 0: $counts['avail']++; break;
			case 1: $counts['unknown']++; break;
			default: $counts['unavail']++;	// unavailable (2) and mixed (3) both alarm
		}
	}

	return [$counts['avail'], $counts['unavail'], $counts['unknown'],
		$monitored - $with_agent, $with_agent, $monitored];
}

/**
 * All active trigger problems (r_eventid IS NULL) on monitored hosts with the
 * host name attached, deduplicated per event (one trigger can reference
 * several items). Sorted in PHP by severity, then age.
 */
function monProblems(): array {
	$res = DBselect(
		'SELECT DISTINCT ON (p.eventid) p.eventid, p.name, p.severity, p.clock, p.acknowledged,'.
		' h.hostid, h.name AS host'.
		' FROM problem p'.
		' JOIN functions f ON f.triggerid=p.objectid'.
		' JOIN items i ON i.itemid=f.itemid'.
		' JOIN hosts h ON h.hostid=i.hostid AND h.status='.HOST_STATUS_MONITORED.
		' WHERE p.r_eventid IS NULL'.
		' ORDER BY p.eventid'
	);

	$rows = [];

	while ($row = DBfetch($res)) {
		$rows[] = $row;
	}

	usort($rows, static fn($a, $b) =>
		((int) $b['severity'] <=> (int) $a['severity'])
			?: ((int) $b['clock'] <=> (int) $a['clock']));

	return $rows;
}

/**
 * Host group name per hostid (first group alphabetically, like flowDbHosts),
 * plus monitored-host counts per group for the groups table.
 */
function monGroups(): array {
	$by_host = [];
	$res = DBselect(
		'SELECT hg.hostid, MIN(g.name) AS grp'.
		' FROM hosts_groups hg'.
		' JOIN hstgrp g ON g.groupid=hg.groupid'.
		' JOIN hosts h ON h.hostid=hg.hostid AND h.status='.HOST_STATUS_MONITORED.
		' GROUP BY hg.hostid'
	);

	while ($row = DBfetch($res)) {
		$grp = $row['grp'] === '0' ? '' : $row['grp'];
		$by_host[$row['hostid']] = $grp;
	}

	$counts = [];
	$res = DBselect(
		'SELECT g.groupid, g.name AS grp, COUNT(DISTINCT hg.hostid) AS hosts'.
		' FROM hstgrp g'.
		' JOIN hosts_groups hg ON hg.groupid=g.groupid'.
		' JOIN hosts h ON h.hostid=hg.hostid AND h.status='.HOST_STATUS_MONITORED.
		' GROUP BY g.groupid, g.name ORDER BY g.name'
	);

	while ($row = DBfetch($res)) {
		$counts[$row['groupid']] = ['name' => $row['grp'], 'hosts' => (int) $row['hosts'],
			'problems' => 0, 'sev' => -1];
	}

	return [$by_host, $counts];
}

/**
 * Enabled items / triggers on monitored hosts (prototypes excluded; triggers
 * counted once even with several functions). Unsupported items live in the
 * runtime table item_rtdata — items has no state column in 7.4.
 */
function monInventory(): array {
	$items = DBfetch(DBselect(
		'SELECT COUNT(*) FILTER (WHERE i.status=0) AS enabled,'.
		' COUNT(*) FILTER (WHERE i.status=0 AND COALESCE(r.state,0)<>0) AS bad'.
		' FROM items i'.
		' JOIN hosts h ON h.hostid=i.hostid AND h.status='.HOST_STATUS_MONITORED.
		' LEFT JOIN item_rtdata r ON r.itemid=i.itemid'.
		' WHERE i.flags<>1'
	));

	$triggers = DBfetch(DBselect(
		'SELECT COUNT(DISTINCT t.triggerid) FILTER (WHERE t.status=0) AS enabled,'.
		' COUNT(DISTINCT t.triggerid) FILTER (WHERE t.status=0 AND t.value=1) AS problem'.
		' FROM triggers t'.
		' JOIN functions f ON f.triggerid=t.triggerid'.
		' JOIN items i ON i.itemid=f.itemid'.
		' JOIN hosts h ON h.hostid=i.hostid AND h.status='.HOST_STATUS_MONITORED.
		' WHERE t.flags<>1'
	));

	return [(int) $items['enabled'], (int) $items['bad'],
		(int) $triggers['enabled'], (int) $triggers['problem']];
}

/**
 * Problem / recovery trigger-event counts of the last 24 hours.
 */
function monEvents24(): array {
	$row = DBfetch(DBselect(
		'SELECT COUNT(*) FILTER (WHERE value=1) AS problem,'.
		' COUNT(*) FILTER (WHERE value=0) AS recovered'.
		' FROM events'.
		' WHERE source=0 AND object=0 AND clock>='.(time() - 86400)
	));

	return [(int) $row['problem'], (int) $row['recovered']];
}

/**
 * Problem events (source 0, object 0, value 1) bucketed for the stacked bar
 * chart: one row per bucket in [{t, v: [count per severity 0..5]}], zeros
 * included so the x axis is continuous. Bucket size comes from MON_GBUCKET.
 */
function monEventSeries(int $from_b, int $to_b, int $bucket): array {
	$rows = [];

	for ($b = $from_b; $b <= $to_b; $b += $bucket) {
		$rows[] = ['t' => $b, 'v' => [0, 0, 0, 0, 0, 0]];
	}

	$idx = [];

	foreach ($rows as $i => $row) {
		$idx[$row['t']] = $i;
	}

	$res = DBselect(
		'SELECT (clock/'.intval($bucket).') AS b, severity, COUNT(*) AS cnt'.
		' FROM events'.
		' WHERE source=0 AND object=0 AND value=1 AND clock>='.$from_b.' AND clock<='.($to_b + $bucket - 1).
		' GROUP BY b, severity'
	);

	while ($row = DBfetch($res)) {
		$b = (int) $row['b'] * $bucket;

		if (isset($idx[$b])) {
			$rows[$idx[$b]]['v'][(int) $row['severity']] = (int) $row['cnt'];
		}
	}

	return $rows;
}

/**
 * Problems resolved within the last 24 hours (rows stay in the problem table
 * with r_clock set once the trigger recovers), newest recovery first.
 */
function monRecovered(int $from): array {
	$res = DBselect(
		'SELECT DISTINCT ON (p.eventid) p.eventid, p.name, p.severity, p.clock, p.r_clock,'.
		' h.name AS host'.
		' FROM problem p'.
		' JOIN functions f ON f.triggerid=p.objectid'.
		' JOIN items i ON i.itemid=f.itemid'.
		' JOIN hosts h ON h.hostid=i.hostid AND h.status='.HOST_STATUS_MONITORED.
		' WHERE p.r_eventid IS NOT NULL AND p.r_clock >= '.$from.
		' ORDER BY p.eventid'
	);

	$rows = [];

	while ($row = DBfetch($res)) {
		$rows[] = $row;
	}

	usort($rows, static fn($a, $b) => (int) $b['r_clock'] <=> (int) $a['r_clock']);

	return $rows;
}

/**
 * Current in/out bps per host that exposes interface traffic items — read
 * straight from Zabbix history with the latest-value-per-item pattern
 * (topo_tree.php uses the same). Returns [{name, group, ip, in_bps, out_bps,
 * clock}] sorted by total rate; clock is the freshest sample of that host.
 */
function monLive(): array {
	$meta = [];
	$res = DBselect(
		'SELECT i.itemid, h.hostid, h.name AS host, i.key_ AS key_, MIN(g.name) AS grp, MIN(ii.ip) AS ip'.
		' FROM items i'.
		' JOIN hosts h ON h.hostid=i.hostid AND h.status='.HOST_STATUS_MONITORED.
		' LEFT JOIN hosts_groups hg ON hg.hostid=h.hostid'.
		' LEFT JOIN hstgrp g ON g.groupid=hg.groupid'.
		' LEFT JOIN interface ii ON ii.hostid=h.hostid AND ii.main=1'.
		' WHERE i.status=0 AND i.value_type=3'.
		' AND (i.key_ LIKE \'net.if.in[%\' OR i.key_ LIKE \'net.if.out[%\')'.
		' AND i.key_ NOT LIKE \'%dropped%\' AND i.key_ NOT LIKE \'%errors%\''.
		' AND i.key_ NOT LIKE \'%{#%\''.
		' AND i.key_ NOT LIKE \'%"lo"%\''.
		' GROUP BY i.itemid, h.hostid, h.name, i.key_'
	);

	while ($row = DBfetch($res)) {
		$meta[$row['itemid']] = $row;
	}

	if (!$meta) {
		return [];
	}

	$ids = implode(',', array_map('intval', array_keys($meta)));
	$res = DBselect('SELECT DISTINCT ON (itemid) itemid, value, clock FROM history_uint'.
		' WHERE itemid IN ('.$ids.') AND clock >= '.(time() - 86400).
		' ORDER BY itemid, clock DESC'
	);

	$out = [];

	while ($row = DBfetch($res)) {
		$m = $meta[$row['itemid']];
		$dir = str_starts_with($m['key_'], 'net.if.in[') ? 'in' : 'out';

		if (!isset($out[$m['host']])) {
			$out[$m['host']] = ['hostid' => (int) $m['hostid'], 'name' => $m['host'],
				'group' => $m['grp'], 'ip' => $m['ip'],
				'in' => 0, 'out' => 0, 'clock' => 0];
		}

		$out[$m['host']][$dir] += (int) $row['value'];
		$out[$m['host']]['clock'] = max($out[$m['host']]['clock'], (int) $row['clock']);
	}

	$rows = [];

	foreach ($out as $h) {
		// DBfetch maps NULL to '0' — a host without group/interface would
		// render as "0 · 0"; show empty instead
		$ip = $h['ip'] === '0' ? '' : $h['ip'];
		$grp = $h['group'] === '0' ? '' : $h['group'];

		$rows[] = ['hostid' => $h['hostid'], 'name' => $h['name'], 'group' => $grp, 'ip' => $ip,
			'in_bps' => $h['in'], 'out_bps' => $h['out'], 'clock' => $h['clock']];
	}

	usort($rows, static fn($a, $b) =>
		($b['in_bps'] + $b['out_bps']) <=> ($a['in_bps'] + $a['out_bps']));

	return $rows;
}

/**
 * Assemble the full page payload for a window: KPI counts, current problems,
 * the event series, availability slices, live interface traffic and the three
 * summary tables. All values come from the live Zabbix database.
 */
function monData(string $window): array {
	$now = time();
	$bucket = MON_GBUCKET[$window];
	$to_b = intdiv($now, $bucket) * $bucket;
	$from_b = $to_b - MON_WINDOWS[$window] + $bucket;

	[$avail, $unavail, $unknown, $agentless, $with_agent, $monitored] = monAvailability();
	[$items, $items_bad, $triggers, $triggers_problem] = monInventory();
	[$events24, $recovered24] = monEvents24();

	$problems = monProblems();
	[$host_groups, $group_counts] = monGroups();

	$sev_hist = [0, 0, 0, 0, 0, 0];
	$unack = 0;
	$by_host = [];	// hostid => [name, count, worst sev]
	$problem_rows = [];

	foreach ($problems as $p) {
		$sev = (int) $p['severity'];
		$sev_hist[$sev]++;
		$unack += $p['acknowledged'] ? 0 : 1;

		$hostid = (int) $p['hostid'];
		$grp = $host_groups[$hostid] ?? '';

		$problem_rows[] = ['id' => (int) $p['eventid'], 'name' => $p['name'], 'sev' => $sev,
			'clock' => (int) $p['clock'], 'ack' => (int) $p['acknowledged'],
			'host' => $p['host'], 'hostid' => $hostid, 'group' => $grp];

		if (!isset($by_host[$hostid])) {
			$by_host[$hostid] = ['hostid' => $hostid, 'name' => $p['host'], 'group' => $grp,
				'cnt' => 0, 'sev' => -1];
		}

		$by_host[$hostid]['cnt']++;
		$by_host[$hostid]['sev'] = max($by_host[$hostid]['sev'], $sev);
	}

	// problems per group, matched on the host's display group
	$group_problems = [];

	foreach ($problem_rows as $p) {
		if ($p['group'] === '') {
			continue;
		}

		foreach ($group_counts as $gid => $g) {
			if ($g['name'] === $p['group']) {
				$group_problems[$gid] = ($group_problems[$gid] ?? 0) + 1;
			}
		}
	}

	$worst = -1;

	for ($s = 5; $s >= 0; $s--) {
		if ($sev_hist[$s] > 0) {
			$worst = $s;
			break;
		}
	}

	// top hosts by active problems (count desc, then worst severity desc)
	$host_rows = array_values($by_host);
	usort($host_rows, static fn($a, $b) =>
		($b['cnt'] <=> $a['cnt']) ?: ($b['sev'] <=> $a['sev'])
			?: strcmp($a['name'], $b['name']));
	$host_rows = array_slice($host_rows, 0, MON_TOP_TABLES);

	// group table rows: hosts, open problems, worst severity seen in group
	$group_rows = [];

	foreach ($group_counts as $gid => $g) {
		if ($g['name'] === '') {
			continue;
		}

		$cnt = $group_problems[$gid] ?? 0;
		$group_rows[] = ['gid' => $gid, 'name' => $g['name'], 'hosts' => $g['hosts'],
			'cnt' => $cnt];
	}

	usort($group_rows, static fn($a, $b) =>
		($b['cnt'] <=> $a['cnt']) ?: strcmp($a['name'], $b['name']));
	$group_rows = array_slice($group_rows, 0, MON_TOP_TABLES);

	$recovered = [];

	foreach (array_slice(monRecovered($now - 86400), 0, MON_TOP_TABLES) as $r) {
		$recovered[] = ['id' => (int) $r['eventid'], 'name' => $r['name'], 'host' => $r['host'],
			'sev' => (int) $r['severity'], 'clock' => (int) $r['clock'],
			'r_clock' => (int) $r['r_clock'],
			'dur' => max(0, (int) $r['r_clock'] - (int) $r['clock'])];
	}

	$donut = [];
	foreach ([['avail', $avail], ['unavail', $unavail], ['unknown', $unknown],
			['agentless', $agentless]] as [$key, $cnt]) {
		$donut[] = ['key' => $key, 'count' => $cnt];
	}

	return [
		'window' => $window,
		'from' => $from_b,
		'g' => $bucket,
		'sev_names' => [
			CSeverityHelper::getName(TRIGGER_SEVERITY_NOT_CLASSIFIED),
			CSeverityHelper::getName(TRIGGER_SEVERITY_INFORMATION),
			CSeverityHelper::getName(TRIGGER_SEVERITY_WARNING),
			CSeverityHelper::getName(TRIGGER_SEVERITY_AVERAGE),
			CSeverityHelper::getName(TRIGGER_SEVERITY_HIGH),
			CSeverityHelper::getName(TRIGGER_SEVERITY_DISASTER)
		],
		'kpi' => [
			'hosts' => $monitored,
			'disabled' => monNotMonitoredCount(),
			'templates' => monTemplateCount(),
			'problems' => count($problem_rows),
			'unack' => $unack,
			'worst' => $worst,
			'avail_pct' => $with_agent > 0 ? round(100 * $avail / $with_agent) : null,
			'agent_unavail' => $unavail,
			'agent_unknown' => $unknown,
			'items' => $items,
			'items_bad' => $items_bad,
			'triggers' => $triggers,
			'triggers_problem' => $triggers_problem,
			'events24' => $events24,
			'recovered24' => $recovered24
		],
		'problems' => array_slice($problem_rows, 0, MON_TOP_PROBLEMS),
		'series' => monEventSeries($from_b, $to_b, $bucket),
		'donut' => $donut,
		'live' => monLive(),
		'tables' => ['hosts' => $host_rows, 'groups' => $group_rows, 'recovered' => $recovered]
	];
}

/**
 * Host counts by status (prototypes excluded): not monitored hosts and
 * templates — companions of the monitored count from monAvailability().
 */
function monNotMonitoredCount(): int {
	$row = DBfetch(DBselect(
		'SELECT COUNT(*) AS cnt FROM hosts WHERE status='.HOST_STATUS_NOT_MONITORED.' AND flags<>1'
	));

	return (int) $row['cnt'];
}

function monTemplateCount(): int {
	$row = DBfetch(DBselect(
		'SELECT COUNT(*) AS cnt FROM hosts WHERE status='.HOST_STATUS_TEMPLATE.' AND flags<>1'
	));

	return (int) $row['cnt'];
}

// ---- request handling -------------------------------------------------------

$window = getRequest('window', '6h');
$window = isset(MON_WINDOWS[$window]) ? $window : '6h';

if (getRequest('ajax') === '1' && getRequest('mode') === 'data') {
	$data = monData($window);

	session_write_close();

	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS
		| JSON_HEX_AMP | JSON_HEX_QUOT
	);
	exit;
}

$data = monData($window);

// UI strings for the client-side script, translated by the user's language.
$i18n = [
	'updated' => _('Updated %1$s'),
	'range' => _('Time range'),
	'w_1h' => _('Last hour'),
	'w_6h' => _('Last 6 hours'),
	'w_24h' => _('Last 24 hours'),
	'w_7d' => _('Last 7 days'),
	'k_hosts' => _('Monitored hosts'),
	'k_hosts_sub' => _('%1$s disabled · %2$s templates'),
	'k_problems' => _('Active problems'),
	'k_unack' => _('%1$s unacknowledged'),
	'k_avail' => _('Agent availability'),
	'k_avail_sub' => _('%1$s down · %2$s unknown'),
	'k_items' => _('Items enabled'),
	'k_items_sub' => _('%1$s not supported'),
	'k_triggers' => _('Triggers enabled'),
	'k_triggers_sub' => _('%1$s in problem state'),
	'k_events' => _('Problem events (24 h)'),
	'k_events_sub' => _('%1$s recovered'),
	'peak' => _('peak %1$s'),
	'p_title' => _('Current problems'),
	'p_sub' => _('Open problems, worst first'),
	'p_none' => _('No active problems.'),
	'ack' => _('ACK'),
	'c_bars' => _('Problem events over time'),
	'c_bars_sub' => _('by severity'),
	'c_donut' => _('Availability'),
	'c_donut_sub' => _('agent interface status'),
	'd_avail' => _('Reachable'),
	'd_unavail' => _('Unreachable'),
	'd_unknown' => _('Status unknown'),
	'd_agentless' => _('No agent interface'),
	'hosts_word' => _('hosts'),
	'no_events' => _('No problem events in this range.'),
	'events_total' => _('%1$s events'),
	'total' => _('Total'),
	't_hosts' => _('Top hosts by problems'),
	't_hosts_sub' => _('active problems per host'),
	't_groups' => _('Host groups'),
	't_groups_sub' => _('monitored hosts and open problems'),
	't_recovered' => _('Recovered (24 h)'),
	't_recovered_sub' => _('problems resolved in the last 24 hours'),
	'col_problems' => _('Problems'),
	'col_worst' => _('Worst'),
	'col_hosts' => _('Hosts'),
	'col_problem' => _('Problem'),
	'col_duration' => _('Duration'),
	'col_resolved' => _('Resolved'),
	'lasted' => _('lasted %1$s'),
	'live_title' => _('Live interface traffic'),
	'live_sub' => _('in / out, straight from Zabbix history'),
	'live_none' => _('No monitored host exposes interface traffic items.'),
	'age_now' => _('just now'),
	'age_ago' => _('%1$s ago'),
	'no_data' => _('No data for this range.'),
	'conn_lost' => _('Connection lost — retrying.'),
	'a_event' => _('View event details'),
	'a_latest' => _('Latest data for this host'),
	'a_group' => _('Open problems in this group'),
	'a_hosts' => _('Monitoring → Hosts'),
	'a_problems' => _('Monitoring → Problems'),
	'a_latestdata' => _('Monitoring → Latest data')
];

session_write_close();

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="<?= str_starts_with(CWebUser::$data['lang'], 'th') ? 'th' : 'en' ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Monitoring summary — Zabbix</title>
	<link rel="stylesheet" type="text/css" href="assets/styles/modern-theme.css">
	<style>
		/* the stock theme pins the SPA at 100vh with body overflow hidden
		   (its own .wrapper scrolls) — this page is a normal scrolling
		   document, so undo both before anything else */
		html, body { height: auto; }
		body { display: block; overflow: auto; }

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
			/* severity status ramp, ordered by alarm level (slate → blue → amber
			   → orange → red); lightness falls while chroma rises toward Disaster,
			   hues are spread ≥ 17° apart, all ≥ 3:1 on --surface (validated) */
			--sev0: #64798e;
			--sev1: #5590e6;
			--sev2: #dfaf35;
			--sev3: #f08e48;
			--sev4: #f76b45;
			--sev5: #ff5566;
			/* availability slices: green / red / slate / blue */
			--av-ok: #38d17e;
			--av-bad: #ff5566;
			--av-unk: #64798e;
			--av-less: #5590e6;
		}

		body { background: var(--bg); margin: 0; font-family: Sarabun, Arial, Tahoma, sans-serif;
			color: var(--text); font-size: 14px; }
		.mono { font-family: ui-monospace, 'Cascadia Code', Consolas, monospace; }

		.mdash { display: flex; flex-direction: column; min-height: 100vh; }

		/* ---- top bar ---- */
		.mdash .topbar { background: linear-gradient(180deg, var(--surface-2), var(--surface));
			border-bottom: 1px solid var(--line); padding: 10px 18px;
			display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
		.mdash .topbar .mark { width: 26px; height: 26px; flex: 0 0 auto; }
		.mdash .topbar h1 { font-size: 17px; margin: 0; font-weight: 700; letter-spacing: .01em; }
		.mdash .topbar .sub { color: var(--text-dim); font-size: 12px; margin-top: 1px; }
		.mdash .topbar .spacer { flex: 1; }
		.mdash .topbar .back { color: var(--accent); text-decoration: none; font-size: 13.5px; }
		.mdash .topbar .back:hover { text-decoration: underline; }
		.mdash .topbar .stamp { color: var(--text-dim); font-size: 12px; }

		/* ---- toolbar (one row above everything it scopes) ---- */
		.mdash .toolbar { display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
			padding: 12px 18px 0; }
		.mdash .toolbar .lbl { color: var(--text-dim); font-size: 12px; }
		.mdash .seg { display: inline-flex; border: 1px solid var(--line); border-radius: 8px;
			overflow: hidden; background: var(--surface); }
		.mdash .seg button { appearance: none; border: 0; background: transparent; color: var(--text-dim);
			font: inherit; font-size: 13px; padding: 6px 13px; cursor: pointer; }
		.mdash .seg button + button { border-left: 1px solid var(--line-soft); }
		.mdash .seg button:hover { color: var(--text); background: var(--surface-2); }
		.mdash .seg button.is-on { color: #fff; background: var(--accent-soft); font-weight: 600; }
		.mdash .seg button:focus-visible { outline: 2px solid var(--accent); outline-offset: -2px; }
		.mdash .autohint { color: var(--text-dim); font-size: 12px; margin-left: auto; }
		.mdash .autohint .dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%;
			background: var(--ok); margin-right: 5px; vertical-align: 1px; }

		/* ---- KPI stat tiles ---- */
		.mdash .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
			gap: 12px; padding: 12px 18px 0; }
		.mdash .kpi { background: var(--surface); border: 1px solid var(--line-soft);
			border-radius: 10px; padding: 10px 14px 9px; }
		.mdash .kpi .lbl { color: var(--text-dim); font-size: 12px; display: flex;
			align-items: center; gap: 6px; }
		.mdash .kpi .lbl .sdot { width: 8px; height: 8px; border-radius: 50%; flex: 0 0 auto; }
		.mdash .kpi .val { font-size: 22px; font-weight: 600; margin-top: 2px; line-height: 1.2;
			font-variant-numeric: tabular-nums; }
		.mdash .kpi .sub { color: var(--text-dim); font-size: 12px; margin-top: 2px;
			overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

		/* ---- current problems ---- */
		.mdash .problems { padding: 12px 18px 0; }
		.mdash .card { background: var(--surface); border: 1px solid var(--line-soft);
			border-radius: 10px; padding: 12px 14px; position: relative; }
		.mdash .card h3 { margin: 0; font-size: 14px; font-weight: 700; }
		.mdash .card .csub { color: var(--text-dim); font-size: 12px; margin-top: 1px; }
		.mdash .prow { display: grid;
			grid-template-columns: 3px 128px minmax(0, 1.1fr) minmax(0, 1.6fr) 48px 66px;
			gap: 10px; align-items: center; padding: 6px 2px 6px 8px;
			border-bottom: 1px solid var(--line-soft); position: relative; }
		.mdash .prow:last-child { border-bottom: 0; }
		.mdash .prow .edge { position: absolute; left: 0; top: 5px; bottom: 5px; width: 3px;
			border-radius: 2px; }
		.mdash .prow .sev { display: flex; align-items: center; gap: 6px;
			color: var(--text-dim); font-size: 12px; white-space: nowrap; overflow: hidden;
			text-overflow: ellipsis; }
		.mdash .prow .sev .dot { width: 8px; height: 8px; border-radius: 50%; flex: 0 0 auto; }
		.mdash .prow .hn { min-width: 0; }
		.mdash .prow .hn .n { font-weight: 600; font-size: 13px; overflow: hidden;
			text-overflow: ellipsis; white-space: nowrap; }
		.mdash .prow .hn .s { color: var(--text-dim); font-size: 11.5px; overflow: hidden;
			text-overflow: ellipsis; white-space: nowrap; }
		.mdash .prow .pn { min-width: 0; font-size: 13px; overflow: hidden; text-overflow: ellipsis;
			white-space: nowrap; }
		.mdash .prow .ack { justify-self: start; font-size: 10.5px; letter-spacing: .04em;
			color: var(--text-dim); border: 1px solid var(--line); border-radius: 5px;
			padding: 1px 6px; background: var(--surface-2); }
		.mdash .prow .age { text-align: right; color: var(--text-dim); font-size: 12px;
			font-variant-numeric: tabular-nums; white-space: nowrap; }
		.mdash .pnone { color: var(--text-dim); padding: 10px 2px; font-size: 13px; }

		/* clickable rows and text links */
		.mdash .prow.is-link, .mdash .lrow.is-link, .mdash .trow.is-link { cursor: pointer; }
		.mdash .prow.is-link:hover, .mdash .lrow.is-link:hover, .mdash .trow.is-link:hover
			{ background: var(--surface-2); }
		.mdash a.hlnk { color: inherit; text-decoration: none; }
		.mdash .prow .pn { color: inherit; text-decoration: none; }
		.mdash a.hlnk:hover, .mdash .prow .pn:hover { color: var(--accent); text-decoration: underline; }
		.mdash a.kpi { display: block; text-decoration: none; color: inherit; }
		.mdash .kpi.is-link:hover { border-color: var(--line); background: var(--surface-2); }

		/* ---- charts row ---- */
		.mdash .charts { display: grid; grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr);
			gap: 12px; padding: 12px 18px 0; }
		@media (max-width: 900px) {
			.mdash .charts { grid-template-columns: minmax(0, 1fr); }
			.mdash .donutwrap svg { width: clamp(140px, 40%, 190px); }
		}
		.mdash .chartbox { position: relative; margin-top: 8px; }
		.mdash .chartbox svg { display: block; width: 100%; height: auto; }
		.mdash .tip { position: absolute; pointer-events: none; background: var(--surface-2);
			border: 1px solid var(--line); border-radius: 8px; padding: 7px 10px;
			font-size: 12px; box-shadow: 0 6px 18px rgba(0, 0, 0, .4); display: none;
			min-width: 150px; z-index: 5; }
		.mdash .tip .tt { color: var(--text-dim); margin-bottom: 4px; }
		.mdash .tip .tr { display: flex; align-items: center; gap: 7px; padding: 1px 0; }
		.mdash .tip .key { width: 10px; height: 2px; flex: 0 0 auto; border-radius: 1px; }
		.mdash .tip .tv { font-weight: 600; font-variant-numeric: tabular-nums; margin-left: auto;
			padding-left: 12px; }
		.mdash .tip .tn { color: var(--text-dim); overflow: hidden; text-overflow: ellipsis;
			white-space: nowrap; max-width: 150px; }
		.mdash .legend { display: flex; flex-wrap: wrap; gap: 4px 14px; margin-top: 8px; }
		.mdash .legend span { color: var(--text-dim); font-size: 12px; display: inline-flex;
			align-items: center; gap: 6px; }
		.mdash .legend .sw { width: 10px; height: 10px; border-radius: 3px; flex: 0 0 auto; }

		/* donut card layout: chart left, HTML legend right */
		.mdash .donutwrap { display: flex; align-items: center; gap: 14px; margin-top: 8px; }
		.mdash .donutwrap svg { width: 190px; height: auto; flex: 0 0 auto; }
		.mdash .dlegend { display: flex; flex-direction: column; gap: 6px; min-width: 0; flex: 1; }
		.mdash .dlegend .dl { display: grid; grid-template-columns: 12px minmax(0, 1fr) auto;
			gap: 7px; align-items: center; font-size: 12.5px; }
		.mdash .dlegend .sw { width: 10px; height: 10px; border-radius: 3px; }
		.mdash .dlegend .dn { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.mdash .dlegend .dv { color: var(--text-dim); font-variant-numeric: tabular-nums; }

		/* ---- live interface traffic (real Zabbix values) ---- */
		.mdash .livesec { padding: 12px 18px 0; }
		.mdash .livesec h3 .dot { display: inline-block; width: 8px; height: 8px;
			border-radius: 50%; background: var(--ok); margin-right: 7px;
			vertical-align: 1.5px; }
		.mdash .livegrid { display: grid;
			grid-template-columns: repeat(auto-fit, minmax(min(300px, 100%), 1fr));
			gap: 2px 20px; margin-top: 8px; }
		.mdash .lrow { display: flex; align-items: center; gap: 12px; min-width: 0;
			padding: 5px 0; }
		.mdash .lrow .ln { min-width: 0; flex: 1; }
		.mdash .lrow .ln .n { font-weight: 600; font-size: 13px; overflow: hidden;
			text-overflow: ellipsis; white-space: nowrap; }
		.mdash .lrow .ln .s { color: var(--text-dim); font-size: 11.5px; overflow: hidden;
			text-overflow: ellipsis; white-space: nowrap; }
		.mdash .lrow .lv { display: flex; gap: 10px; text-align: right;
			font-variant-numeric: tabular-nums; font-size: 12.5px; white-space: nowrap; }
		.mdash .lrow .lv .in { color: var(--ok); }
		.mdash .lrow .lv .out { color: var(--accent); }
		.mdash .lrow .age { color: var(--text-dim); font-size: 11px; white-space: nowrap; }
		.mdash .lnone { color: var(--text-dim); padding: 6px 0; font-size: 13px; }

		/* ---- summary tables ---- */
		.mdash .tables { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(340px, 100%), 1fr));
			gap: 12px; padding: 12px 18px 18px; }
		.mdash .tcard { background: var(--surface); border: 1px solid var(--line-soft);
			border-radius: 10px; padding: 12px 14px 8px; }
		.mdash .tcard h3 { margin: 0; font-size: 14px; font-weight: 700; }
		.mdash .tcard .csub { color: var(--text-dim); font-size: 12px; margin-top: 1px; }
		.mdash .thead, .mdash .trow { display: grid; gap: 10px; align-items: center; }
		.mdash .thead { color: var(--text-dim); font-size: 11.5px; padding: 9px 2px 5px;
			border-bottom: 1px solid var(--line-soft); }
		.mdash .thead .num, .mdash .thead .cval { text-align: right; }
		.mdash .trow { padding: 6px 2px; border-bottom: 1px solid var(--line-soft); }
		.mdash .trow:last-child { border-bottom: 0; }
		.mdash .trow .rank { color: var(--text-dim); font-variant-numeric: tabular-nums;
			font-size: 12px; }
		.mdash .trow .rank.is-top { color: var(--accent); font-weight: 700; }
		.mdash .trow .nm { min-width: 0; }
		.mdash .trow .nm .n { font-weight: 600; overflow: hidden; text-overflow: ellipsis;
			white-space: nowrap; }
		.mdash .trow .nm .s { color: var(--text-dim); font-size: 11.5px; overflow: hidden;
			text-overflow: ellipsis; white-space: nowrap; }
		.mdash .trow .nm .bar { height: 4px; border-radius: 2px; background: var(--accent);
			margin-top: 4px; opacity: .85; }
		.mdash .trow .v { text-align: right; font-variant-numeric: tabular-nums; font-size: 12.5px; }
		.mdash .trow .v.on { font-weight: 600; }
		.mdash .trow .v.dim { color: var(--text-dim); }
		.mdash .trow .sdot { display: inline-block; width: 9px; height: 9px; border-radius: 50%;
			vertical-align: -1px; }
		.mdash .trow .slabel { display: inline-flex; align-items: center; gap: 5px;
			color: var(--text-dim); font-size: 11.5px; white-space: nowrap; }
		.mdash .tnone { color: var(--text-dim); padding: 12px 2px; font-size: 13px; }

		/* per-table column layouts */
		.mdash [data-table="hosts"] .thead, .mdash [data-table="hosts"] .trow
			{ grid-template-columns: 24px minmax(0, 1fr) 74px 96px; }
		.mdash [data-table="groups"] .thead, .mdash [data-table="groups"] .trow
			{ grid-template-columns: 24px minmax(0, 1fr) 60px 84px; }
		.mdash [data-table="recovered"] .thead, .mdash [data-table="recovered"] .trow
			{ grid-template-columns: 24px minmax(0, 1fr) 92px 72px 64px; }

		/* refetch keeps the frame: hold the previous render, dimmed */
		.mdash .dim .kpis, .mdash .dim .problems, .mdash .dim .charts, .mdash .dim .livesec,
			.mdash .dim .tables { opacity: .55; transition: opacity .15s; }

		/* ---- toast ---- */
		#toast { position: fixed; left: 50%; bottom: 26px; transform: translateX(-50%);
			background: var(--surface-2); color: var(--text); border: 1px solid var(--line);
			border-radius: 8px; padding: 8px 14px; font-size: 13px; opacity: 0;
			pointer-events: none; transition: opacity .2s; z-index: 20; }
		#toast.is-on { opacity: 1; }

		@media (prefers-reduced-motion: reduce) {
			.mdash * { transition: none !important; }
		}

		/* ---- phone layout: full-width segmented controls, 2-col KPIs,
		   stacked donut, tighter tables ---- */
		@media (max-width: 560px) {
			.mdash .topbar { padding: 10px 12px; }
			.mdash .toolbar { padding: 10px 12px 0; gap: 10px; }
			.mdash .toolbar .lbl { flex-basis: 100%; }
			.mdash .seg { display: flex; width: 100%; }
			.mdash .seg button { flex: 1; min-width: 0; padding: 7px 8px; font-size: 12.5px;
				overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
			.mdash .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); padding: 10px 12px 0; }
			.mdash .kpi .val { font-size: 19px; }
			.mdash .problems { padding: 10px 12px 0; }
			.mdash .charts { padding: 10px 12px 0; }
			.mdash .livesec { padding: 10px 12px 0; }
			.mdash .tables { padding: 10px 12px 12px; }
			.mdash .donutwrap { flex-direction: column; align-items: stretch; }
			.mdash .donutwrap svg { width: min(160px, 60%); height: auto; margin: 0 auto; }
			.mdash [data-table="hosts"] .thead, .mdash [data-table="hosts"] .trow
				{ grid-template-columns: 18px minmax(0, 1fr) 56px 74px; }
			.mdash [data-table="groups"] .thead, .mdash [data-table="groups"] .trow
				{ grid-template-columns: 18px minmax(0, 1fr) 48px 64px; }
			.mdash [data-table="recovered"] .thead, .mdash [data-table="recovered"] .trow
				{ grid-template-columns: 18px minmax(0, 1fr) 74px 58px 52px; }
		}

		/* very narrow phones: drop the severity name, ACK chip and Duration
		   column so the names stay readable */
		@media (max-width: 420px) {
			.mdash .prow { grid-template-columns: 3px 22px minmax(0, 1.1fr) minmax(0, 1.6fr) 66px; }
			.mdash .prow .sev .sn { display: none; }
			.mdash .prow .ack { display: none; }
			.mdash [data-table="recovered"] .thead, .mdash [data-table="recovered"] .trow
				{ grid-template-columns: 16px minmax(0, 1fr) 58px 56px; }
			.mdash [data-table="recovered"] .cdur { display: none; }
			.mdash .lrow .age { display: none; }
		}
	</style>
</head>
<body>
<div class="mdash" id="root">
	<div class="topbar">
		<svg class="mark" viewBox="0 0 24 24" aria-hidden="true"><path fill="none"
			stroke="#3fa2ff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
			d="M3 13h4l2-6 3 12 3-9 2 3h4"/></svg>
		<div>
			<h1><?= _('Monitoring summary') ?></h1>
			<div class="sub"><?= _('Live status of the monitored Zabbix environment — hosts, problems and availability.') ?></div>
		</div>
		<div class="spacer"></div>
		<span class="stamp" id="stamp"></span>
		<a class="back" href="zabbix.php"><?= _('← Back to main menu') ?></a>
	</div>

	<div class="toolbar">
		<span class="lbl"><?= _('Time range') ?></span>
		<div class="seg" id="seg-range" role="group" aria-label="<?= _('Time range') ?>">
			<button type="button" data-w="1h"><?= _('Last hour') ?></button>
			<button type="button" data-w="6h"><?= _('Last 6 hours') ?></button>
			<button type="button" data-w="24h"><?= _('Last 24 hours') ?></button>
			<button type="button" data-w="7d"><?= _('Last 7 days') ?></button>
		</div>
		<span class="autohint"><span class="dot"></span>60 s</span>
	</div>

	<div class="kpis" id="kpis"></div>

	<div class="problems">
		<div class="card">
			<h3><?= _('Current problems') ?></h3>
			<div class="csub"><?= _('Open problems, worst first') ?></div>
			<div id="probbox"></div>
		</div>
	</div>

	<div class="charts">
		<div class="card">
			<h3><?= _('Problem events over time') ?></h3>
			<div class="csub"><?= _('by severity') ?></div>
			<div class="chartbox" id="areabox">
				<svg id="barchart" viewBox="0 0 900 300" preserveAspectRatio="xMidYMid meet"
					role="img" aria-label=""></svg>
				<div class="tip" id="bartip"></div>
			</div>
			<div class="legend" id="barlegend"></div>
		</div>
		<div class="card">
			<h3><?= _('Availability') ?></h3>
			<div class="csub"><?= _('agent interface status') ?></div>
			<div class="donutwrap">
				<svg id="donut" viewBox="0 0 200 200" role="img" aria-label=""></svg>
				<div class="dlegend" id="dlegend"></div>
			</div>
		</div>
	</div>

	<div class="livesec">
		<div class="card">
			<h3><span class="dot"></span><?= _('Live interface traffic') ?></h3>
			<div class="csub"><?= _('in / out, straight from Zabbix history') ?></div>
			<div class="livegrid" id="livebox"></div>
		</div>
	</div>

	<div class="tables" id="tables">
		<div class="tcard" data-table="hosts">
			<h3><?= _('Top hosts by problems') ?></h3>
			<div class="csub"><?= _('active problems per host') ?></div>
			<div class="thead">
				<span class="num">#</span>
				<span><?= _('Host') ?></span>
				<span class="cval"><?= _('Problems') ?></span>
				<span class="cval"><?= _('Worst') ?></span>
			</div>
			<div class="tbody"></div>
		</div>
		<div class="tcard" data-table="groups">
			<h3><?= _('Host groups') ?></h3>
			<div class="csub"><?= _('monitored hosts and open problems') ?></div>
			<div class="thead">
				<span class="num">#</span>
				<span><?= _('Group') ?></span>
				<span class="cval"><?= _('Hosts') ?></span>
				<span class="cval"><?= _('Problems') ?></span>
			</div>
			<div class="tbody"></div>
		</div>
		<div class="tcard" data-table="recovered">
			<h3><?= _('Recovered (24 h)') ?></h3>
			<div class="csub"><?= _('problems resolved in the last 24 hours') ?></div>
			<div class="thead">
				<span class="num">#</span>
				<span><?= _('Problem') ?></span>
				<span><?= _('Worst') ?></span>
				<span class="cdur"><?= _('Duration') ?></span>
				<span class="cval"><?= _('Resolved') ?></span>
			</div>
			<div class="tbody"></div>
		</div>
	</div>
</div>

<div id="toast" role="status"></div>

<script>
const DATA = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS
	| JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const T = <?= json_encode($i18n, JSON_UNESCAPED_UNICODE) ?>;

// severity status ramp — see the :root comment; index = severity 0..5
const SEV_COLORS = ['#64798e', '#5590e6', '#dfaf35', '#f08e48', '#f76b45', '#ff5566'];
// availability slices by key
const AVAIL_COLORS = {avail: '#38d17e', unavail: '#ff5566', unknown: '#64798e',
	agentless: '#5590e6'};
const DONUT_LABELS = {avail: T.d_avail, unavail: T.d_unavail, unknown: T.d_unknown,
	agentless: T.d_agentless};

const TH_UI = document.documentElement.lang === 'th';

let state = {window: DATA.window};
let lastData = null;
let inflight = false;

// ---- navigation targets for the clickable rows and tiles ----------------------

const urlEvent = id => 'tr_events.php?eventid=' + encodeURIComponent(id);
const urlLatest = id => 'zabbix.php?' + new URLSearchParams(
	{action: 'latest.view', 'hostids[]': id, filter_set: '1'});
const urlProblemsHost = id => 'zabbix.php?' + new URLSearchParams(
	{action: 'problem.view', 'hostids[]': id, filter_set: '1'});
const urlProblemsGroup = id => 'zabbix.php?' + new URLSearchParams(
	{action: 'problem.view', 'groupids[]': id, filter_set: '1'});
const urlHosts = 'zabbix.php?action=host.view';
const urlProblems = 'zabbix.php?action=problem.view';
const urlLatestView = 'zabbix.php?action=latest.view';

// make a whole row open url in a new tab; clicks on real anchors inside the
// row are left to the anchor itself
function rowLink(row, url) {
	row.classList.add('is-link');

	row.addEventListener('click', e => {
		if (e.button !== 0 || e.target.closest('a')) return;

		window.open(url, '_blank', 'noopener');
	});
}

// a plain link that looks like the surrounding text
function textLink(href, title, text) {
	const a = document.createElement('a');
	a.className = 'hlnk';
	a.href = href;
	a.target = '_blank';
	a.rel = 'noopener';
	a.title = title;
	a.textContent = text;

	return a;
}

const escHtml = s => String(s).replace(/[&<>"']/g,
	c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

// compact counts: 1,284 / 12.9K / 4.2M
function fmtNum(v) {
	if (!isFinite(v)) return '0';

	if (v < 10000) return Math.round(v).toLocaleString('en-US');

	const units = ['K', 'M', 'B'];
	let u = -1;

	while (v >= 1000 && u < units.length - 1) {
		v /= 1000;
		u++;
	}

	return v.toFixed(v < 10 ? 1 : 0) + units[u];
}

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

function fmtTime(t) {
	const d = new Date(t * 1000);
	const o = TH_UI ? 'th-TH' : 'en-GB';

	return d.toLocaleTimeString(o, {hour: '2-digit', minute: '2-digit', hour12: false});
}

function fmtDay(t) {
	const d = new Date(t * 1000);
	const o = TH_UI ? 'th-TH' : 'en-GB';

	return d.toLocaleDateString(o, {day: 'numeric', month: 'short'}) + ' ' +
		d.toLocaleTimeString(o, {hour: '2-digit', minute: '2-digit', hour12: false});
}

function fmtAge(clock) {
	const s = Math.max(0, Math.floor(Date.now() / 1000) - clock);

	if (s < 90) return T.age_now;

	let v, u;

	if (s < 3600) {
		v = Math.round(s / 60); u = 'min';
	}
	else if (s < 86400) {
		v = Math.round(s / 3600); u = 'h';
	}
	else {
		v = Math.round(s / 86400); u = 'd';
	}

	return T.age_ago.replace('%1$s', v + ' ' + u);
}

// compact duration: 45m / 3h 12m / 2d 4h
function fmtDur(s) {
	s = Math.max(0, Math.round(s));

	if (s < 60) return s + 'm';

	const parts = [];

	if (s >= 86400) {
		parts.push(Math.floor(s / 86400) + 'd');
		s %= 86400;
	}

	if (s >= 3600) {
		parts.push(Math.floor(s / 3600) + 'h');
		s %= 3600;
	}

	if (s >= 60 && parts.length < 2) {
		parts.push(Math.floor(s / 60) + 'm');
	}

	return parts.join(' ') || '0m';
}

// ---- KPI tiles ---------------------------------------------------------------

function renderKpis(d) {
	const box = document.getElementById('kpis');
	box.textContent = '';

	const k = d.kpi;
	const worstColor = k.worst >= 0 ? SEV_COLORS[k.worst] : null;

	const tiles = [
		[T.k_hosts, fmtNum(k.hosts),
			T.k_hosts_sub.replace('%1$s', fmtNum(k.disabled)).replace('%2$s', fmtNum(k.templates)),
			null, urlHosts, T.a_hosts],
		[T.k_problems, fmtNum(k.problems),
			T.k_unack.replace('%1$s', fmtNum(k.unack)),
			worstColor, urlProblems, T.a_problems],
		[T.k_avail, k.avail_pct === null ? '—' : k.avail_pct + '%',
			T.k_avail_sub.replace('%1$s', fmtNum(k.agent_unavail)).replace('%2$s', fmtNum(k.agent_unknown)),
			null, urlHosts, T.a_hosts],
		[T.k_items, fmtNum(k.items),
			T.k_items_sub.replace('%1$s', fmtNum(k.items_bad)),
			null, urlLatestView, T.a_latestdata],
		[T.k_triggers, fmtNum(k.triggers),
			T.k_triggers_sub.replace('%1$s', fmtNum(k.triggers_problem)), null, null, ''],
		[T.k_events, fmtNum(k.events24),
			T.k_events_sub.replace('%1$s', fmtNum(k.recovered24)), null, null, '']
	];

	for (const [lbl, val, sub, dot, url, title] of tiles) {
		let tile;

		if (url) {
			tile = document.createElement('a');
			tile.className = 'kpi is-link';
			tile.href = url;
			tile.target = '_blank';
			tile.rel = 'noopener';
			tile.title = title;
		}
		else {
			tile = document.createElement('div');
			tile.className = 'kpi';
		}

		const l = document.createElement('div');
		l.className = 'lbl';

		if (dot) {
			const sd = document.createElement('span');
			sd.className = 'sdot';
			sd.style.background = dot;
			l.append(sd);
		}

		l.append(document.createTextNode(lbl));

		const v = document.createElement('div');
		v.className = 'val';
		v.textContent = val;

		const s = document.createElement('div');
		s.className = 'sub';
		s.textContent = sub;

		tile.append(l, v, s);
		box.append(tile);
	}
}

// ---- current problems ----------------------------------------------------------

function renderProblems(d) {
	const box = document.getElementById('probbox');
	box.textContent = '';

	const rows = d.problems || [];

	if (!rows.length) {
		const none = document.createElement('div');
		none.className = 'pnone';
		none.textContent = T.p_none;
		box.append(none);

		return;
	}

	for (const p of rows) {
		const row = document.createElement('div');
		row.className = 'prow';
		rowLink(row, urlEvent(p.id));

		const edge = document.createElement('span');
		edge.className = 'edge';
		edge.style.background = SEV_COLORS[p.sev];
		row.append(edge);

		const sev = document.createElement('span');
		sev.className = 'sev';

		const dot = document.createElement('span');
		dot.className = 'dot';
		dot.style.background = SEV_COLORS[p.sev];

		const sn = document.createElement('span');
		sn.className = 'sn';
		sn.textContent = d.sev_names[p.sev];

		sev.append(dot, sn);

		const hn = document.createElement('span');
		hn.className = 'hn';

		const n = document.createElement('div');
		n.className = 'n';
		n.append(textLink(urlLatest(p.hostid), T.a_latest, p.host));

		const s = document.createElement('div');
		s.className = 's';
		s.textContent = p.group;

		hn.append(n, s);

		const pn = document.createElement('a');
		pn.className = 'pn';
		pn.href = urlEvent(p.id);
		pn.target = '_blank';
		pn.rel = 'noopener';
		pn.title = T.a_event;
		pn.textContent = p.name;

		row.append(sev, hn, pn);

		// the ack cell always occupies its grid column (hidden when unacked)
		const ack = document.createElement('span');
		ack.className = 'ack';

		if (p.ack) {
			ack.textContent = T.ack;
		}
		else {
			ack.style.visibility = 'hidden';
		}

		row.append(ack);

		const age = document.createElement('span');
		age.className = 'age';
		age.textContent = fmtAge(p.clock);

		row.append(age);
		box.append(row);
	}
}

// ---- stacked bar chart -------------------------------------------------------

// chart geometry follows the container width continuously (same scheme as the
// traffic page it replaces): viewBox width = measured card width, margins and
// tick counts ease between a compact narrow plot and the roomy desktop one
function barGeom(wpx) {
	const W = Math.max(300, Math.round(wpx));
	const k = Math.min(1, Math.max(0, (W - 440) / (900 - 440)));	// 0 narrow → 1 wide

	return {
		W,
		H: Math.round(340 - 40 * k),
		M: {l: Math.round(40 + 10 * k), r: Math.round(8 + 6 * k), t: 12, b: Math.round(24 + 4 * k)},
		ticks: k < 0.45 ? 3 : 5,
		font: k < 0.45 ? 10 : 11
	};
}

// count y-max: divisible by 4 so the 4 gridlines land on whole numbers
function niceCount(v) {
	for (const m of [4, 8, 12, 16, 20, 24, 32, 40, 48, 64, 80, 100, 120, 160, 200,
		240, 320, 400, 480, 640, 800, 1000, 1200, 1600, 2000, 3200, 4000, 6400, 8000]) {
		if (m >= v) return m;
	}

	return Math.ceil(v / 4) * 4;
}

function renderBars(d) {
	const svg = document.getElementById('barchart');
	const rows = d.series;

	svg.querySelector('g.plot')?.remove();
	svg.querySelector('rect.hit')?.remove();
	svg.querySelector('line.cross')?.remove();

	const legend = document.getElementById('barlegend');
	legend.textContent = '';

	for (let s = 0; s < 6; s++) {
		const sp = document.createElement('span');
		const sw = document.createElement('span');
		sw.className = 'sw';
		sw.style.background = SEV_COLORS[s];
		sp.append(sw, document.createTextNode(d.sev_names[s]));
		legend.append(sp);
	}

	const total = rows.reduce((a, r) => a + r.v.reduce((x, y) => x + y, 0), 0);

	if (!total) {
		svg.setAttribute('aria-label', T.no_events);

		return;
	}

	const G = barGeom(document.getElementById('areabox').clientWidth || 900);
	const W = G.W, H = G.H, M = G.M;
	svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);

	const pw = W - M.l - M.r, ph = H - M.t - M.b;

	let ymax = 0;

	for (const r of rows) {
		const tot = r.v.reduce((a, b) => a + b, 0);

		if (tot > ymax) ymax = tot;
	}

	ymax = niceCount(ymax);
	const t0 = d.from, tg = d.g;
	const slot = pw / rows.length;			// x space per bucket
	const bw = Math.min(26, slot * 0.62);	// bar width inside the slot
	const x = t => M.l + slot * (t - t0) / tg + (slot - bw) / 2;
	const y = v => M.t + ph * (1 - v / ymax);

	let g = '<g class="plot">';

	// hairline gridlines + y ticks (whole numbers, text tokens)
	for (let i = 0; i <= 4; i++) {
		const val = ymax * i / 4;
		const yy = y(val);

		g += '<line x1="'+M.l+'" y1="'+yy+'" x2="'+(W-M.r)+'" y2="'+yy+
			'" stroke="#1a2942" stroke-width="1"/>';
		g += '<text x="'+(M.l-8)+'" y="'+(yy+4)+'" text-anchor="end" font-size="'+G.font+'" '+
			'fill="#8296b3" style="font-variant-numeric:tabular-nums">'+fmtNum(val)+'</text>';
	}

	// x ticks: readable positions across the window
	const span = rows[rows.length - 1].t - t0;

	for (let i = 0; i <= G.ticks; i++) {
		const t = t0 + Math.round(span * i / G.ticks);
		const xx = M.l + slot * (t - t0) / tg + slot / 2;

		g += '<line x1="'+xx+'" y1="'+(M.t+ph)+'" x2="'+xx+'" y2="'+(M.t+ph+4)+
			'" stroke="#24344f" stroke-width="1"/>';
		g += '<text x="'+xx+'" y="'+(M.t+ph+18)+'" text-anchor="middle" font-size="'+G.font+'" '+
			'fill="#8296b3">'+(d.window === '7d' ? fmtDay(t) : fmtTime(t))+'</text>';
	}

	// stacked severity segments, low severity at the base; 1.5px surface gap
	// between segments (skipped on tiny segments), rounded data-end on top
	for (const r of rows) {
		const bx = x(r.t);
		const tot = r.v.reduce((a, b) => a + b, 0);

		if (!tot) continue;

		for (let s = 0; s < 6; s++) {
			if (!r.v[s]) continue;

			const below = r.v.slice(0, s).reduce((a, b) => a + b, 0);
			const yTop = y(below + r.v[s]);
			const yBot = y(below);
			const h = yBot - yTop;

			if (h < 1) continue;

			const gap = (s > 0 && h > 4) ? 1.5 : 0;

			// is this the outermost (data-end) segment?
			const isTop = !r.v.slice(s + 1).some(x => x);

			if (isTop && h >= 6 && bw >= 8) {
				// rounded top corners (4px-scale data end, clipped to height)
				const rr = Math.min(3, h / 2);

				g += '<path d="M'+bx.toFixed(1)+' '+(yBot-gap).toFixed(1)+
					' L'+bx.toFixed(1)+' '+(yTop+rr).toFixed(1)+
					' Q'+bx.toFixed(1)+' '+yTop.toFixed(1)+' '+(bx+rr).toFixed(1)+' '+yTop.toFixed(1)+
					' L'+(bx+bw-rr).toFixed(1)+' '+yTop.toFixed(1)+
					' Q'+(bx+bw).toFixed(1)+' '+yTop.toFixed(1)+' '+(bx+bw).toFixed(1)+' '+(yTop+rr).toFixed(1)+
					' L'+(bx+bw).toFixed(1)+' '+(yBot-gap).toFixed(1)+' Z" fill="'+SEV_COLORS[s]+'"/>';
			}
			else {
				g += '<rect x="'+bx.toFixed(1)+'" y="'+yTop.toFixed(1)+'" width="'+bw.toFixed(1)+
					'" height="'+Math.max(1, h-gap).toFixed(1)+'" fill="'+SEV_COLORS[s]+'"/>';
			}
		}
	}

	// baseline
	g += '<line x1="'+M.l+'" y1="'+(M.t+ph)+'" x2="'+(W-M.r)+'" y2="'+(M.t+ph)+
		'" stroke="#24344f" stroke-width="1"/>';
	g += '</g>';
	svg.insertAdjacentHTML('beforeend', g);

	// hover layer: crosshair finds the bucket, one tooltip lists its severities
	const peak = Math.max(...rows.map(r => r.v.reduce((a, b) => a + b, 0)));
	svg.setAttribute('aria-label', T.c_bars + ' (' + T.c_bars_sub + '), ' +
		T.events_total.replace('%1$s', fmtNum(total)) + ', ' + T.peak.replace('%1$s', fmtNum(peak)));

	attachBarHover(svg, rows, d.sev_names, x, slot, M, W, H, t0, tg);
}

function attachBarHover(svg, rows, names, x, slot, M, W, H, t0, tg) {
	const box = document.getElementById('areabox');
	const tip = document.getElementById('bartip');

	const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
	rect.setAttribute('class', 'hit');
	rect.setAttribute('x', M.l);
	rect.setAttribute('y', M.t);
	rect.setAttribute('width', W - M.l - M.r);
	rect.setAttribute('height', H - M.t - M.b);
	rect.setAttribute('fill', 'transparent');
	rect.setAttribute('tabindex', '0');

	const guide = document.createElementNS('http://www.w3.org/2000/svg', 'line');
	guide.setAttribute('class', 'cross');
	guide.setAttribute('stroke', '#3fa2ff');
	guide.setAttribute('stroke-width', '1');
	guide.setAttribute('y1', M.t);
	guide.setAttribute('y2', H - M.b);
	guide.style.display = 'none';

	svg.append(guide, rect);

	let idx = -1;

	const show = i => {
		idx = Math.max(0, Math.min(rows.length - 1, i));
		const r = rows[idx];
		const cx = x(r.t) + slot / 2;

		guide.setAttribute('x1', cx);
		guide.setAttribute('x2', cx);
		guide.style.display = '';

		// tooltip: total first, then the non-zero severities
		tip.textContent = '';

		const tt = document.createElement('div');
		tt.className = 'tt';
		tt.textContent = fmtDay(r.t);
		tip.append(tt);

		const tot = r.v.reduce((a, b) => a + b, 0);

		for (let s = 5; s >= 0; s--) {
			if (!r.v[s]) continue;

			const tr = document.createElement('div');
			tr.className = 'tr';

			const key = document.createElement('span');
			key.className = 'key';
			key.style.background = SEV_COLORS[s];

			const tv = document.createElement('span');
			tv.className = 'tv';
			tv.textContent = fmtNum(r.v[s]);

			const tn = document.createElement('span');
			tn.className = 'tn';
			tn.textContent = names[s];

			tr.append(key, tn, tv);
			tip.append(tr);
		}

		if (!tot) {
			const tr = document.createElement('div');
			tr.className = 'tr';
			tr.textContent = T.no_events;
			tip.append(tr);
		}
		else {
			const tr = document.createElement('div');
			tr.className = 'tr';

			const tv = document.createElement('span');
			tv.className = 'tv';
			tv.textContent = fmtNum(tot);

			const tn = document.createElement('span');
			tn.className = 'tn';
			tn.textContent = T.total;

			tr.append(tn, tv);
			tip.append(tr);
		}

		tip.style.display = 'block';

		// keep the tooltip inside the card
		const boxr = box.getBoundingClientRect();
		const px = cx / W * boxr.width;
		tip.style.left = Math.max(0, Math.min(boxr.width - 180, px + 12)) + 'px';
		tip.style.top = '8px';
	};

	const hide = () => {
		idx = -1;
		guide.style.display = 'none';
		tip.style.display = 'none';
	};

	const nearest = px => {
		// buckets are uniform: invert the slot scale directly
		const t = t0 + Math.round((px - M.l) / slot) * tg;

		return Math.max(0, Math.min(rows.length - 1,
			Math.round((t - t0) / tg)));
	};

	rect.addEventListener('pointermove', e => {
		const boxr = box.getBoundingClientRect();
		const px = (e.clientX - boxr.left) / boxr.width * W;

		show(nearest(px));
	});
	rect.addEventListener('pointerleave', hide);
	rect.addEventListener('focus', () => show(idx < 0 ? rows.length - 1 : idx));
	rect.addEventListener('blur', hide);
	rect.addEventListener('keydown', e => {
		if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
			e.preventDefault();
			show((idx < 0 ? rows.length - 1 : idx) + (e.key === 'ArrowRight' ? 1 : -1));
		}
		else if (e.key === 'Escape') {
			hide();
		}
	});
}

// ---- availability donut --------------------------------------------------------

const ARC = {
	polar(cx, cy, r, deg) {
		const rad = (deg - 90) * Math.PI / 180;

		return [cx + r * Math.cos(rad), cy + r * Math.sin(rad)];
	},
	arcPath(cx, cy, r, a0, a1) {
		const [x0, y0] = this.polar(cx, cy, r, a1);
		const [x1, y1] = this.polar(cx, cy, r, a0);
		const large = (a1 - a0) > 180 ? 1 : 0;

		return 'M '+x0.toFixed(2)+' '+y0.toFixed(2)+' A '+r+' '+r+' 0 '+large+' 0 '+
			x1.toFixed(2)+' '+y1.toFixed(2);
	}
};

function renderDonut(d) {
	const svg = document.getElementById('donut');
	const legend = document.getElementById('dlegend');

	svg.textContent = '';
	legend.textContent = '';

	const slices = (d.donut || []).filter(s => s.count > 0);
	const total = (d.donut || []).reduce((a, s) => a + s.count, 0);

	if (!total) {
		svg.setAttribute('aria-label', T.no_data);

		return;
	}

	const CX = 100, CY = 100, R = 78, W = 26;
	const full = slices.length === 1;

	let a = 0;
	const arcs = [];

	for (const s of slices) {
		const frac = s.count / total;
		const a1 = full ? a + 360 : a + frac * 360;
		arcs.push([s, a, a1, frac]);
		a = a1;
	}

	// 2px surface gaps between segments: draw each slice as a stroked arc
	// slightly narrower than the ring so the surface shows through; a single
	// 100% slice can't be one arc (zero-length path) — draw a full circle
	for (const [s, a0, a1] of arcs) {
		const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');

		if (a1 - a0 >= 359.9) {
			path.setAttribute('d', ARC.arcPath(CX, CY, R - W / 2, 0, 180) +
				' ' + ARC.arcPath(CX, CY, R - W / 2, 180, 360));
		}
		else {
			if (a1 - a0 <= 0.5) continue;

			path.setAttribute('d', ARC.arcPath(CX, CY, R - W / 2, a0 + 1.2, Math.max(a0 + 1.3, a1 - 1.2)));
		}

		path.setAttribute('fill', 'none');
		path.setAttribute('stroke', AVAIL_COLORS[s.key]);
		path.setAttribute('stroke-width', W - 2.5);
		path.setAttribute('stroke-linecap', 'butt');
		svg.append(path);

		path.addEventListener('pointerenter', () => {
			path.setAttribute('stroke-opacity', '.75');
		});
		path.addEventListener('pointerleave', () => {
			path.setAttribute('stroke-opacity', '1');
		});
	}

	// center: the part-to-whole headline
	const t1 = document.createElementNS('http://www.w3.org/2000/svg', 'text');
	t1.setAttribute('x', CX);
	t1.setAttribute('y', CY - 4);
	t1.setAttribute('text-anchor', 'middle');
	t1.setAttribute('font-size', '17');
	t1.setAttribute('font-weight', '600');
	t1.setAttribute('fill', '#dce8f7');
	t1.textContent = fmtNum(total);

	const t2 = document.createElementNS('http://www.w3.org/2000/svg', 'text');
	t2.setAttribute('x', CX);
	t2.setAttribute('y', CY + 16);
	t2.setAttribute('text-anchor', 'middle');
	t2.setAttribute('font-size', '11');
	t2.setAttribute('fill', '#8296b3');
	t2.textContent = T.hosts_word;

	svg.append(t1, t2);

	svg.setAttribute('aria-label', T.c_donut + ': ' +
		slices.slice(0, 3).map(s => DONUT_LABELS[s.key] + ' ' + s.count).join(', '));

	// HTML legend rows (values readable without hover) — fixed slice order
	for (const s of (d.donut || [])) {
		const row = document.createElement('div');
		row.className = 'dl';

		const sw = document.createElement('span');
		sw.className = 'sw';
		sw.style.background = AVAIL_COLORS[s.key];

		const nm = document.createElement('span');
		nm.className = 'dn';
		nm.textContent = DONUT_LABELS[s.key];

		const dv = document.createElement('span');
		dv.className = 'dv';
		dv.textContent = fmtNum(s.count) + ' · ' +
			(total ? Math.round(100 * s.count / total) : 0) + '%';

		row.append(sw, nm, dv);
		legend.append(row);
	}
}

// ---- live interface traffic (real Zabbix values) ------------------------------

function renderLive(d) {
	const box = document.getElementById('livebox');
	box.textContent = '';

	const rows = d.live || [];

	if (!rows.length) {
		const none = document.createElement('div');
		none.className = 'lnone';
		none.textContent = T.live_none;
		box.append(none);

		return;
	}

	for (const h of rows) {
		const row = document.createElement('div');
		row.className = 'lrow';
		rowLink(row, urlLatest(h.hostid));

		const ln = document.createElement('div');
		ln.className = 'ln';

		const n = document.createElement('div');
		n.className = 'n';
		n.append(textLink(urlLatest(h.hostid), T.a_latest, h.name));

		const s = document.createElement('div');
		s.className = 's';
		s.textContent = [h.ip, h.group].filter(Boolean).join(' · ');

		ln.append(n, s);

		const lv = document.createElement('div');
		lv.className = 'lv';

		const vi = document.createElement('span');
		vi.className = 'in';
		vi.textContent = '↓ ' + fmtBps(h.in_bps);

		const vo = document.createElement('span');
		vo.className = 'out';
		vo.textContent = '↑ ' + fmtBps(h.out_bps);

		lv.append(vi, vo);

		const age = document.createElement('span');
		age.className = 'age';
		age.textContent = fmtAge(h.clock);

		row.append(ln, lv, age);
		box.append(row);
	}
}

// ---- summary tables -----------------------------------------------------------

function renderTables(d) {
	const root = document.getElementById('tables');

	for (const card of root.querySelectorAll('.tcard')) {
		const key = card.dataset.table;
		const body = card.querySelector('.tbody');
		const rows = d.tables[key] || [];

		body.textContent = '';

		if (!rows.length) {
			const none = document.createElement('div');
			none.className = 'tnone';
			none.textContent = T.no_data;
			body.append(none);

			continue;
		}

		const max = key === 'recovered' ? 0 : Math.max(...rows.map(r => r.cnt), 1);

		// rows navigate: hosts → latest data, groups → their problems,
		// recoveries → the original event details
		const navFor = r => key === 'hosts'
			? urlLatest(r.hostid)
			: key === 'groups' ? urlProblemsGroup(r.gid) : urlEvent(r.id);
		const navTitle = key === 'hosts'
			? T.a_latest
			: key === 'groups' ? T.a_group : T.a_event;

		rows.forEach((r, i) => {
			const row = document.createElement('div');
			row.className = 'trow';
			rowLink(row, navFor(r));

			const nav = navFor(r);

			const rank = document.createElement('span');
			rank.className = 'rank' + (i < 3 ? ' is-top' : '');
			rank.textContent = String(i + 1);

			const nm = document.createElement('span');
			nm.className = 'nm';

			const n = document.createElement('div');
			n.className = 'n';
			n.append(textLink(nav, navTitle, r.name));

			const s = document.createElement('div');
			s.className = 's';
			s.textContent = key === 'recovered'
				? r.host
				: r.group;

			nm.append(n, s);

			// share bar for count-based tables
			if (max && r.cnt) {
				const bar = document.createElement('div');
				bar.className = 'bar';
				bar.style.width = Math.max(2, Math.round(100 * r.cnt / max)) + '%';
				nm.append(bar);
			}

			row.append(rank, nm);

			if (key === 'hosts') {
				const v = document.createElement('span');
				v.className = 'v on';
				v.textContent = fmtNum(r.cnt);
				row.append(v);

				const w = document.createElement('span');
				w.className = 'slabel';
				const dot = document.createElement('span');
				dot.className = 'sdot';
				dot.style.background = SEV_COLORS[r.sev];
				w.append(dot, document.createTextNode(d.sev_names[r.sev]));
				row.append(w);
			}
			else if (key === 'groups') {
				const v1 = document.createElement('span');
				v1.className = 'v dim';
				v1.textContent = fmtNum(r.hosts);
				row.append(v1);

				const v2 = document.createElement('span');
				v2.className = 'v on';
				v2.textContent = fmtNum(r.cnt);
				row.append(v2);
			}
			else {
				const sev = document.createElement('span');
				sev.className = 'slabel';
				const dot = document.createElement('span');
				dot.className = 'sdot';
				dot.style.background = SEV_COLORS[r.sev];
				sev.append(dot, document.createTextNode(d.sev_names[r.sev]));
				row.append(sev);

				const dur = document.createElement('span');
				dur.className = 'v dim cdur';
				dur.textContent = fmtDur(r.dur);
				row.append(dur);

				const res = document.createElement('span');
				res.className = 'v on';
				res.title = T.lasted.replace('%1$s', fmtDur(r.dur));
				res.textContent = fmtAge(r.r_clock);
				row.append(res);
			}

			body.append(row);
		});
	}
}

// ---- plumbing ----------------------------------------------------------------

function stamp() {
	document.getElementById('stamp').textContent =
		T.updated.replace('%1$s', new Date().toLocaleTimeString(TH_UI ? 'th-TH' : 'en-GB', {hour12: false}));
}

function buildAll(d) {
	lastData = d;
	document.getElementById('root').classList.remove('dim');
	renderKpis(d);
	renderProblems(d);
	renderBars(d);
	renderDonut(d);
	renderLive(d);
	renderTables(d);
	stamp();
}

async function refresh(silent) {
	if (inflight) return;
	inflight = true;

	document.getElementById('root').classList.add('dim');

	try {
		const url = 'mon_dashboard.php?' + new URLSearchParams({
			ajax: '1', mode: 'data', window: state.window
		});
		const res = await fetch(url);
		const json = await res.json();

		if (json && json.kpi) {
			state = {window: json.window};
			buildAll(json);
		}
	}
	catch (e) {
		document.getElementById('root').classList.remove('dim');

		if (!silent) toast(T.conn_lost);
	}

	inflight = false;
}

let toastTimer;

function toast(msg) {
	const el = document.getElementById('toast');
	el.textContent = msg;
	el.classList.add('is-on');
	clearTimeout(toastTimer);
	toastTimer = setTimeout(() => el.classList.remove('is-on'), 3500);
}

// toolbar: one row above the charts, scopes the events chart window
for (const btn of document.querySelectorAll('#seg-range button')) {
	btn.addEventListener('click', () => {
		state.window = btn.dataset.w;
		markSeg('seg-range', btn);
		refresh(true);
	});
}

function markSeg(id, btn) {
	for (const b of document.getElementById(id).querySelectorAll('button')) {
		b.classList.toggle('is-on', b === btn);
	}
}

markSeg('seg-range', document.querySelector('#seg-range button[data-w="'+state.window+'"]'));

buildAll(DATA);

// auto-refresh every 60 s — every value on the page is live Zabbix data
setInterval(() => refresh(true), 60000);

// re-fit the bar chart when the layout width changes (desktop resize,
// orientation flip) — donut/KPIs reflow via CSS alone
let rzTimer;

window.addEventListener('resize', () => {
	clearTimeout(rzTimer);
	rzTimer = setTimeout(() => {
		if (lastData) renderBars(lastData);
	}, 150);
});
</script>
</body>
</html>
