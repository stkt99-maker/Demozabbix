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

$page['file'] = 'flow_dashboard.php';

if (!CWebUser::isLoggedIn() || CWebUser::isGuest()) {
	redirect('index.php');
}

define('FLOW_LOCK_KEY', 1179406167);	// ASCII 'FLOW' — advisory lock + generator seed salt
define('FLOW_BUCKET', 300);		// 5-minute record buckets, epoch seconds
define('FLOW_RETENTION', 8 * 86400);	// keep 8 days, so the 7d window stays populated
define('FLOW_SEED_DAYS', 7);		// first render backfills this much history

const FLOW_WINDOWS = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800];
// chart bucket size per window; all multiples of FLOW_BUCKET so chart buckets
// align with the record buckets they aggregate
const FLOW_GBUCKET = ['1h' => 300, '6h' => 300, '24h' => 1800, '7d' => 10800];

/*
** Demo dataset catalogs. Synthetic but plausible traffic for a Thai organisation:
** internal hosts grouped into IP groups, external services with country/city,
** L7 applications with day/night weight profiles. The generator is the only
** writer; these arrays are the single source of truth for the mix.
*/

// [name, proto, day-weight, night-weight, dur min..max s, rate min..max bps, avg packet bytes]
// scope is derived from the destination pools: an app only reaches the wire via
// matching external destinations (scope 'ext'), internal pools ('int') or both.
const FLOW_APPS = [
	// name               proto  wd   wn   durmin durmax  rmin     rmax     pkt
	['HTTPS',             'TCP',  18,  10,    10,   600,  200000,  5000000, 1100],
	['Microsoft 365',     'TCP',  14,   4,    60,  1800,  300000,  3000000, 1000],
	['MS Teams',          'UDP',   9,   3,    60,  3600,  800000,  3500000,  900],
	['Zoom',              'UDP',   5,   1,   300,  3600, 1200000,  4000000,  900],
	['YouTube',           'TCP',   6,  16,    60,  1800, 2000000,  9000000, 1400],
	['Netflix',           'TCP',   1,   9,   900,  7200, 5000000, 16000000, 1500],
	['TikTok',            'TCP',   3,  12,    20,   900, 1500000,  8000000, 1200],
	['Facebook',          'TCP',   5,   7,    15,   600,  500000,  4000000, 1100],
	['LINE',              'TCP',   8,   8,     5,   300,  200000,  2000000,  800],
	['Shopee',            'TCP',   4,   5,    20,   900,  300000,  3000000, 1100],
	['Windows Update',    'TCP',   2,   9,   300,  3600, 6000000, 30000000, 1400],
	['Backup (Veeam)',    'TCP',   1,   5,   600,  3600,  8000000, 60000000, 13000],
	['DNS',               'UDP',  14,  10,     1,     5,    8000,    64000,  120],
	['NTP',               'UDP',   6,   5,     1,     2,    4000,    16000,   90],
	['SNMP monitoring',   'UDP',   5,   5,     1,     3,    4000,    24000,  350],
	['RDP',               'TCP',   6,   2,   300,  7200,  500000,  4000000,  700],
	['SMB file sharing',  'TCP',   5,   3,    30,  1800, 5000000, 60000000, 1400],
	['LDAP / Kerberos',   'TCP',   5,   2,     1,    30,   50000,   800000,  600],
	['SMTP / Exchange',   'TCP',   4,   3,    10,   300,  100000,  2000000, 1200],
	['MySQL',             'TCP',   3,   3,     5,   600,  800000, 20000000, 1000],
	['PostgreSQL',        'TCP',   2,   2,     5,   600,  800000, 15000000, 1000],
	['SSH',               'TCP',   3,   4,    60,  3600,   16000,   512000,  400],
	['Online games',      'UDP',   1,   5,   900,  7200,  300000,  2500000,  300],
	['Docker registry',   'TCP',   1,   2,    60,  1200, 5000000, 40000000, 1400],
	['Git / CI',          'TCP',   2,   3,    30,   900,  100000,  5000000, 1100]
];

// Apps that only make sense between internal hosts (the rest ride external
// destinations; 'both' apps appear in either direction).
const FLOW_INT_ONLY = [
	'SNMP monitoring', 'RDP', 'SMB file sharing', 'LDAP / Kerberos', 'MySQL',
	'PostgreSQL', 'Backup (Veeam)'
];

// External services: [name, ip, country ISO-2, city, IP group, weight, [apps...]]
const FLOW_DESTS = [
	['YouTube CDN',        '104.117.12.5',   'US', 'Ashburn',    'Streaming',            12, ['YouTube']],
	['Netflix CDN',        '45.57.88.10',    'US', 'Seattle',    'Streaming',             7, ['Netflix']],
	['TikTok CDN',         '23.221.211.7',   'SG', 'Singapore',  'Social media',         10, ['TikTok']],
	['Facebook edge',      '157.240.16.35',  'US', 'San Jose',   'Social media',          8, ['Facebook']],
	['LINE API',           '103.2.108.30',   'JP', 'Tokyo',      'Social media',          8, ['LINE']],
	['Shopee TH',          '45.124.94.10',   'TH', 'Bangkok',    'Marketplaces',          7, ['Shopee']],
	['Microsoft 365',      '52.96.13.42',    'US', 'Seattle',    'SaaS & productivity',  10, ['Microsoft 365', 'HTTPS']],
	['Teams relay',        '52.112.44.8',    'US', 'Ashburn',    'SaaS & productivity',   8, ['MS Teams']],
	['Zoom cloud',         '170.114.15.6',   'SG', 'Singapore',  'SaaS & productivity',   5, ['Zoom']],
	['Windows Update',     '20.190.128.10',  'US', 'Ashburn',    'SaaS & productivity',   6, ['Windows Update']],
	['Cloudflare CDN',     '104.16.132.9',   'US', 'San Jose',   'Cloud & CDN',          14, ['HTTPS']],
	['Akamai CDN',         '23.32.44.12',    'SG', 'Singapore',  'Cloud & CDN',           8, ['HTTPS']],
	['AWS ap-southeast',   '13.229.88.4',    'SG', 'Singapore',  'Cloud & CDN',           7, ['HTTPS']],
	['Azure SEA',          '20.198.120.6',   'SG', 'Singapore',  'Cloud & CDN',           5, ['HTTPS']],
	['Google Cloud',       '34.87.10.2',     'SG', 'Singapore',  'Cloud & CDN',           5, ['HTTPS']],
	['Docker Hub',         '44.208.254.194', 'US', 'Ashburn',    'Cloud & CDN',           3, ['Docker registry']],
	['GitHub',             '140.82.112.3',   'US', 'Seattle',    'SaaS & productivity',   4, ['Git / CI']],
	['Steam games',        '155.133.226.8',  'SG', 'Singapore',  'Gaming',                4, ['Online games']],
	['Riot games',         '45.126.79.10',   'JP', 'Osaka',      'Gaming',                3, ['Online games']],
	['GOV cloud (DGA)',    '203.146.107.9',  'TH', 'Bangkok',    'Public internet',       4, ['HTTPS']],
	['Public internet A',  '203.146.129.20', 'TH', 'Bangkok',    'Public internet',       8, ['HTTPS', 'SMTP / Exchange', 'DNS']],
	['Public internet B',  '61.19.54.30',    'TH', 'Chiang Mai', 'Public internet',       5, ['HTTPS', 'DNS']],
	['CDN edge TH',        '27.145.110.15',  'TH', 'Bangkok',    'Cloud & CDN',           5, ['HTTPS']],
	['Partner VPN hub',    '103.75.11.5',    'HK', 'Hong Kong',  'Branch & partners',     4, ['HTTPS', 'SSH']],
	['Seoul SaaS',         '175.126.99.4',   'KR', 'Seoul',      'SaaS & productivity',   3, ['HTTPS']],
	['Frankfurt storage',  '88.198.56.10',   'DE', 'Frankfurt',  'Cloud & CDN',           2, ['HTTPS']],
	['Mumbai DC',          '13.71.80.5',     'IN', 'Mumbai',     'Cloud & CDN',           3, ['HTTPS']],
	['Sydney mirror',      '13.55.101.7',    'AU', 'Sydney',     'SaaS & productivity',   2, ['HTTPS', 'Git / CI']],
	['Amsterdam CDN',      '151.101.64.12',  'NL', 'Amsterdam',  'Cloud & CDN',           3, ['HTTPS']],
	['Shenzhen supplier',  '119.145.8.22',   'CN', 'Shenzhen',   'Branch & partners',     2, ['HTTPS', 'SMTP / Exchange']]
];

// Internal hosts: [name, ip, IP group, weight]
const FLOW_HOSTS = [
	['PC-FIN-01',      '10.10.2.21',     'Office LAN',        3],
	['PC-FIN-02',      '10.10.2.22',     'Office LAN',        3],
	['PC-HR-03',       '10.10.3.31',     'Office LAN',        3],
	['PC-ACC-04',      '10.10.3.32',     'Office LAN',        3],
	['LT-IT-11',       '10.10.8.11',     'Office LAN',        3],
	['PC-ENG-07',      '10.10.9.44',     'Office LAN',        3],
	['PC-PLAN-02',     '10.10.4.15',     'Office LAN',        2],
	['LT-MGT-01',      '10.10.1.5',      'Office LAN',        2],
	['SRV-WEB-01',     '10.20.1.10',     'Server farm',       3],
	['SRV-APP-02',     '10.20.1.11',     'Server farm',       3],
	['SRV-DB-01',      '10.20.2.20',     'Server farm',       3],
	['SRV-DB-02',      '10.20.2.21',     'Server farm',       2],
	['SRV-FILE-01',    '10.20.3.30',     'Server farm',       3],
	['SRV-DC-01',      '10.20.2.5',      'Server farm',       3],
	['DMZ-WEB-01',     '10.30.0.10',     'DMZ',               4],
	['DMZ-MAIL-01',    '10.30.0.11',     'DMZ',               3],
	['DMZ-PROXY-01',   '10.30.0.12',     'DMZ',               4],
	['SCADA-HMI-01',   '172.16.4.10',    'OT / SCADA',        2],
	['OT-HIST-02',     '172.16.4.11',    'OT / SCADA',        2],
	['PLC-GW-01',      '172.16.5.5',     'OT / SCADA',        1],
	['GST-PHONE-01',   '192.168.100.51', 'Guest WiFi',        2],
	['GST-PHONE-02',   '192.168.100.52', 'Guest WiFi',        2],
	['GST-LAPTOP-03',  '192.168.100.53', 'Guest WiFi',        1],
	['BR-CMX-PC-01',   '10.40.1.21',     'Branch offices',    2],
	['BR-CMX-PC-02',   '10.40.1.22',     'Branch offices',    2],
	['BR-KKC-PC-01',   '10.40.2.31',     'Branch offices',    2],
	['BR-HDY-PC-01',   '10.40.3.41',     'Branch offices',    1],
	['MGMT-NMS-01',    '10.99.0.5',      'Management',        2],
	['MGMT-JUMP-01',   '10.99.0.6',      'Management',        2],
	['BKP-NODE-01',    '10.50.1.10',     'Backup & archive',  2],
	['NAS-ARCHIVE-01', '10.50.1.20',     'Backup & archive',  2]
];

// Internal destination pools per app (int flows end here); fallback: any other group.
const FLOW_INT_DST = [
	'SMB file sharing' => ['SRV-FILE-01', 'NAS-ARCHIVE-01'],
	'MySQL'            => ['SRV-DB-01', 'SRV-DB-02'],
	'PostgreSQL'       => ['SRV-DB-01'],
	'RDP'              => ['SRV-APP-02', 'MGMT-JUMP-01'],
	'LDAP / Kerberos'  => ['SRV-DC-01'],
	'SMTP / Exchange'  => ['DMZ-MAIL-01'],
	'Backup (Veeam)'   => ['BKP-NODE-01'],
	'SNMP monitoring'  => ['MGMT-NMS-01'],
	'SSH'              => ['MGMT-JUMP-01', 'SRV-APP-02'],
	'DNS'              => ['SRV-DC-01'],
	'NTP'              => ['SRV-DC-01'],
	'HTTPS'            => ['SRV-WEB-01', 'SRV-APP-02']
];

// Flow exporters (the devices that would emit NetFlow), by traffic class.
const FLOW_EXPORTERS_INT = ['core-sw-01', 'core-sw-01', 'core-sw-01', 'dc-sw-02', 'dc-sw-02'];
const FLOW_EXPORTERS_EDGE = ['inet-gw-01', 'inet-gw-01', 'inet-gw-01', 'fw-edge-01', 'fw-edge-01'];

// Relative load per Bangkok hour (0..23): office hours peak, quiet small hours.
const FLOW_DIURNAL = [
	0 => 0.55, 1 => 0.45, 2 => 0.40, 3 => 0.40, 4 => 0.45, 5 => 0.50,
	6 => 0.70, 7 => 1.00, 8 => 1.50, 9 => 1.80, 10 => 1.90, 11 => 1.85,
	12 => 1.50, 13 => 1.70, 14 => 1.90, 15 => 1.90, 16 => 1.80, 17 => 1.60,
	18 => 1.20, 19 => 1.00, 20 => 0.95, 21 => 0.90, 22 => 0.80, 23 => 0.65
];

/**
 * Does the demo record table exist? (Page degrades gracefully when
 * flow_dashboard.sql has not been applied yet.)
 */
function flowTableExists(): bool {
	return (bool) DBfetch(DBselect(
		'SELECT 1 FROM information_schema.tables'.
		' WHERE table_schema=\'public\' AND table_name=\'flow_record\''
	));
}

/**
 * Deterministically generate all flow records of one 5-minute bucket.
 * A pure function of $bstart: any worker, in any order, produces the exact
 * same rows for the same bucket (mt_srand seeded by the bucket timestamp).
 */
function flowBucketGen(int $bstart): array {
	mt_srand($bstart ^ FLOW_LOCK_KEY);

	$hour = intdiv($bstart + 7 * 3600, 3600) % 24;	// Asia/Bangkok, no DST
	$day = ($hour >= 7 && $hour < 20);
	$count = (int) max(6, min(50, round(24 * FLOW_DIURNAL[$hour])));

	// apps usable in the chosen direction, with the hour's weights
	$ext_apps = [];
	$int_apps = [];
	foreach (FLOW_APPS as $i => $app) {
		if (in_array($app[0], FLOW_INT_ONLY)) {
			$int_apps[$i] = $day ? $app[2] : $app[3];
		}
		else {
			$ext_apps[$i] = $day ? $app[2] : $app[3];
		}
	}

	// external destinations grouped by app name for quick lookup
	$dests_by_app = [];
	foreach (FLOW_DESTS as $i => $d) {
		foreach ($d[6] as $app_name) {
			$dests_by_app[$app_name][] = $i;
		}
	}

	// internal hosts indexed by name; weighted list for generic picks
	$hosts = [];
	foreach (FLOW_HOSTS as $h) {
		$hosts[$h[0]] = ['ip' => $h[1], 'group' => $h[2], 'weight' => $h[3]];
	}
	$host_weights = array_column(FLOW_HOSTS, 3);

	$rows = [];
	for ($seq = 0; $seq < $count; $seq++) {
		$r = mt_rand(1, 100);
		$dir = ($r <= 55) ? 'out' : (($r <= 85) ? 'in' : 'int');

		if ($dir === 'int') {
			$ai = flowPick($int_apps);
			$app = FLOW_APPS[$ai][0];
			$proto = FLOW_APPS[$ai][1];

			$src = FLOW_HOSTS[flowPick($host_weights)];
			$pool = FLOW_INT_DST[$app] ?? null;

			// affinity pool minus the source host itself; falls back to any
			// other internal host when the pool is a single self-hit
			$candidates = ($pool !== null)
				? array_values(array_diff($pool, [$src[0]]))
				: [];

			if ($candidates) {
				$dst_name = $candidates[mt_rand(0, count($candidates) - 1)];
			}
			else {
				do {
					$dst = FLOW_HOSTS[flowPick($host_weights)];
				} while ($dst[0] === $src[0]);
				$dst_name = $dst[0];
			}

			$dst = $hosts[$dst_name];
			$src_name = $src[0];
			$src_ip = $src[1];
			$dst_ip = $dst['ip'];
			$src_group = $src[2];
			$dst_group = $dst['group'];
			$src_country = $dst_country = '';
			$src_city = $dst_city = '';
			$ext_country = $ext_city = null;
			$exporter = FLOW_EXPORTERS_INT[mt_rand(0, count(FLOW_EXPORTERS_INT) - 1)];
		}
		else {
			$ai = flowPick($ext_apps);
			$app = FLOW_APPS[$ai][0];
			$proto = FLOW_APPS[$ai][1];

			// destinations that carry this app (fallback: generic HTTPS carriers)
			$cand = $dests_by_app[$app] ?? $dests_by_app['HTTPS'];
			$di = $cand[flowPick(array_map(static fn($i) => FLOW_DESTS[$i][5], $cand))];
			$dest = FLOW_DESTS[$di];

			$internal = FLOW_HOSTS[flowPick($host_weights)];
			if ($dir === 'out') {
				$src_name = $internal[0];
				$src_ip = $internal[1];
				$src_group = $internal[2];
				$src_country = '';
				$src_city = '';
				$dst_name = $dest[0];
				$dst_ip = $dest[1];
				$dst_group = $dest[4];
				$dst_country = $dest[2];
				$dst_city = $dest[3];
				$ext_country = $dest[2];
				$ext_city = $dest[3];
			}
			else {
				$src_name = $dest[0];
				$src_ip = $dest[1];
				$src_group = $dest[4];
				$src_country = $dest[2];
				$src_city = $dest[3];
				$dst_name = $internal[0];
				$dst_ip = $internal[1];
				$dst_group = $internal[2];
				$dst_country = '';
				$dst_city = '';
				$ext_country = $dest[2];
				$ext_city = $dest[3];
			}

			if ($src_group === 'Branch offices' || $dst_group === 'Branch offices') {
				$exporter = 'wan-rtr-01';
			}
			else {
				$exporter = FLOW_EXPORTERS_EDGE[mt_rand(0, count(FLOW_EXPORTERS_EDGE) - 1)];
			}
		}

		// duration and log-uniform rate inside the app's profile
		$dur = mt_rand(FLOW_APPS[$ai][4], FLOW_APPS[$ai][5]);
		$rmin = FLOW_APPS[$ai][6];
		$rmax = FLOW_APPS[$ai][7];
		$u = mt_rand() / mt_getrandmax();
		$rate = $rmin * pow($rmax / $rmin, $u);
		$bytes = (int) max(1, (int) round($rate * $dur / 8));
		$packets = (int) max(1, (int) round($bytes / FLOW_APPS[$ai][8] * (0.8 + 0.4 * $u)));

		$rows[] = [
			'bstart' => $bstart,
			'seq' => $seq,
			'dur' => $dur,
			'dir' => $dir,
			'src_ip' => $src_ip,
			'dst_ip' => $dst_ip,
			'src_name' => $src_name,
			'dst_name' => $dst_name,
			'src_country' => $src_country,
			'dst_country' => $dst_country,
			'src_city' => $src_city,
			'dst_city' => $dst_city,
			'src_group' => $src_group,
			'dst_group' => $dst_group,
			'ext_country' => $ext_country,
			'ext_city' => $ext_city,
			'app' => $app,
			'proto' => $proto,
			'exporter' => $exporter,
			'bytes' => $bytes,
			'packets' => $packets
		];
	}

	return $rows;
}

/**
 * Weighted pick: $weights maps the wanted key to a positive integer weight;
 * returns one of those keys.
 */
function flowPick(array $weights): int {
	$r = mt_rand(1, max(1, array_sum($weights)));
	$last = null;

	foreach ($weights as $i => $w) {
		$last = $i;
		$r -= $w;

		if ($r <= 0) {
			return $i;
		}
	}

	return $last ?? 0;
}

/**
 * Insert generated rows in 500-row multi-row INSERTs; idempotent on the PK.
 */
function flowInsertRows(array $rows): void {
	foreach (array_chunk($rows, 500) as $chunk) {
		$vals = [];

		foreach ($chunk as $r) {
			$vals[] = '('.implode(',', [
				$r['bstart'], $r['seq'], $r['dur'], zbx_dbstr($r['dir']),
				zbx_dbstr($r['src_ip']), zbx_dbstr($r['dst_ip']),
				zbx_dbstr($r['src_name']), zbx_dbstr($r['dst_name']),
				zbx_dbstr($r['src_country']), zbx_dbstr($r['dst_country']),
				zbx_dbstr($r['src_city']), zbx_dbstr($r['dst_city']),
				zbx_dbstr($r['src_group']), zbx_dbstr($r['dst_group']),
				$r['ext_country'] === null ? 'NULL' : zbx_dbstr($r['ext_country']),
				$r['ext_city'] === null ? 'NULL' : zbx_dbstr($r['ext_city']),
				zbx_dbstr($r['app']), zbx_dbstr($r['proto']), zbx_dbstr($r['exporter']),
				$r['bytes'], $r['packets']
			]).')';
		}

		DBexecute('INSERT INTO flow_record (bstart,seq,dur,dir,src_ip,dst_ip,src_name,dst_name,'.
			'src_country,dst_country,src_city,dst_city,src_group,dst_group,ext_country,ext_city,'.
			'app,proto,exporter,bytes,packets) VALUES '.implode(',', $vals).
			' ON CONFLICT (bstart, seq) DO NOTHING'
		);
	}
}

/**
 * Bring flow_record up to the current bucket: backfill missing buckets
 * (7-day seed on an empty table), prune past retention. Returns the number
 * of buckets added. Advisory try-lock means a concurrent render just skips.
 */
function flowTopUp(): int {
	$now_b = intdiv(time(), FLOW_BUCKET) * FLOW_BUCKET;

	$row = DBfetch(DBselect('SELECT COALESCE(MAX(bstart),0) AS m FROM flow_record'));
	$max = $row ? (int) $row['m'] : 0;

	$start = ($max > 0)
		? $max + FLOW_BUCKET
		: intdiv($now_b - FLOW_SEED_DAYS * 86400, FLOW_BUCKET) * FLOW_BUCKET;

	if ($start > $now_b) {
		return 0;	// already current (or the clock jumped backward — self-heals)
	}

	DBstart();

	$lock = DBfetch(DBselect('SELECT pg_try_advisory_xact_lock('.FLOW_LOCK_KEY.') AS ok'));

	if (!$lock || $lock['ok'] !== 't') {
		DBend(false);

		return 0;
	}

	// double-checked locking: another worker may have caught up while we raced
	$row = DBfetch(DBselect('SELECT COALESCE(MAX(bstart),0) AS m FROM flow_record'));
	$max = $row ? (int) $row['m'] : 0;
	$start = ($max > 0) ? $max + FLOW_BUCKET : $start;

	if ($start > $now_b) {
		DBend(true);

		return 0;
	}

	$added = 0;
	$buf = [];

	for ($b = $start; $b <= $now_b; $b += FLOW_BUCKET) {
		if ($b < $now_b - FLOW_RETENTION) {
			continue;
		}

		foreach (flowBucketGen($b) as $r) {
			$buf[] = $r;
		}

		$added++;

		if (count($buf) >= 500) {
			flowInsertRows($buf);
			$buf = [];
		}
	}

	if ($buf) {
		flowInsertRows($buf);
	}

	DBexecute('DELETE FROM flow_record WHERE bstart < '.($now_b - FLOW_RETENTION));

	DBend(true);

	return $added;
}

/**
 * Build the full dashboard payload for one window/sort combination.
 */
function flowData(string $window, string $sort): array {
	$w = FLOW_WINDOWS[$window];
	$g = FLOW_GBUCKET[$window];
	$to = intdiv(time(), FLOW_BUCKET) * FLOW_BUCKET;
	$from = max(0, intdiv($to - $w, $g) * $g);
	$between = ' WHERE bstart BETWEEN '.$from.' AND '.$to;

	// traffic volume (average flow rate) vs byte count — the two rankings differ
	$order = ($sort === 'bytes') ? '2 DESC, 3 DESC' : '3 DESC, 2 DESC';
	$sel = 'COALESCE(SUM(bytes),0)::bigint AS bytes, '.
		'(SUM(bytes)::numeric*8/NULLIF(SUM(dur),0))::float8 AS bps, COUNT(*)::bigint AS flows';

	// KPI strip
	$kpi = DBfetch(DBselect('SELECT COUNT(*)::bigint AS flows, COALESCE(SUM(bytes),0)::bigint AS bytes,'.
		' COALESCE(SUM(packets),0)::bigint AS packets, COUNT(DISTINCT exporter)::int AS exporters,'.
		' COUNT(DISTINCT ext_country)::int AS countries FROM flow_record'.$between
	));

	// six straight-dimension tables + the two-sided IP group table
	$dims = [
		'src' => 'SELECT src_name AS name, MIN(src_ip) AS sub_ip, MIN(src_group) AS sub_grp,'.
			' MIN(src_country) AS sub_cc, MIN(src_city) AS sub_city, '.$sel.
			' FROM flow_record'.$between.' GROUP BY 1 ORDER BY '.$order.' LIMIT 10',
		'dst' => 'SELECT dst_name AS name, MIN(dst_ip) AS sub_ip, MIN(dst_group) AS sub_grp,'.
			' MIN(dst_country) AS sub_cc, MIN(dst_city) AS sub_city, '.$sel.
			' FROM flow_record'.$between.' GROUP BY 1 ORDER BY '.$order.' LIMIT 10',
		'app' => 'SELECT app AS name, MIN(proto) AS sub_proto, '.$sel.
			' FROM flow_record'.$between.' GROUP BY 1 ORDER BY '.$order.' LIMIT 10',
		'country' => 'SELECT ext_country AS name, '.$sel.
			' FROM flow_record'.$between.' AND ext_country IS NOT NULL GROUP BY 1 ORDER BY '.$order.' LIMIT 10',
		'city' => 'SELECT ext_city AS name, MIN(ext_country) AS sub_cc, '.$sel.
			' FROM flow_record'.$between.' AND ext_city IS NOT NULL GROUP BY 1 ORDER BY '.$order.' LIMIT 10',
		'exporter' => 'SELECT exporter AS name, '.$sel.
			' FROM flow_record'.$between.' GROUP BY 1 ORDER BY '.$order.' LIMIT 10',
		'group' => 'SELECT grp AS name, '.$sel.' FROM ('.
			'SELECT src_group AS grp, bytes, dur FROM flow_record'.$between.
			' UNION ALL SELECT dst_group, bytes, dur FROM flow_record'.$between.
			') t GROUP BY 1 ORDER BY '.$order.' LIMIT 10'
	];

	$tables = [];

	foreach ($dims as $key => $sql) {
		$rows = [];
		$res = DBselect($sql);

		while ($row = DBfetch($res)) {
			$sub = '';

			if ($key === 'src' || $key === 'dst') {
				// internal side: "10.10.2.21 · Office LAN"; external side: country + city
				$sub = ($row['sub_cc'] !== '')
					? $row['sub_ip'].' · '.strtoupper($row['sub_cc']).' '.($row['sub_city'] !== '' ? '· '.$row['sub_city'] : '')
					: $row['sub_ip'].' · '.$row['sub_grp'];
			}
			elseif ($key === 'app') {
				$sub = $row['sub_proto'];
			}
			elseif ($key === 'city') {
				$sub = $row['sub_cc'];
			}

			$rows[] = [
				'name' => $row['name'],
				'sub' => $sub,
				'cc' => ($key === 'country') ? $row['name'] : (($key === 'city') ? $row['sub_cc'] : ''),
				'bytes' => (int) $row['bytes'],
				'bps' => (float) $row['bps'],
				'flows' => (int) $row['flows']
			];
		}

		$tables[$key] = $rows;
	}

	// stacked area chart: per chart-bucket bytes per IP group (both endpoints)
	$grp_rows = [];
	$res = DBselect('SELECT div(bstart, '.$g.')*'.$g.' AS b, grp, COALESCE(SUM(bytes),0)::bigint AS bytes FROM ('.
		'SELECT bstart, src_group AS grp, bytes FROM flow_record'.$between.
		' UNION ALL SELECT bstart, dst_group, bytes FROM flow_record'.$between.
		') t GROUP BY 1, 2 ORDER BY 1'
	);

	while ($row = DBfetch($res)) {
		$grp_rows[] = [(int) $row['b'], $row['grp'], (int) $row['bytes']];
	}

	$grp_totals = [];

	foreach ($grp_rows as $gr) {
		$grp_totals[$gr[1]] = ($grp_totals[$gr[1]] ?? 0) + $gr[2];
	}

	arsort($grp_totals);
	$top_groups = array_slice(array_keys($grp_totals), 0, 5);
	$names = $top_groups;

	if (count($grp_totals) > 5) {
		$names[] = 'Other';
	}

	$bucket_vals = [];

	foreach ($grp_rows as $gr) {
		$bi = (int) round(($gr[0] - $from) / $g);
		$si = array_search($gr[1], $top_groups, true);
		$si = ($si === false) ? count($top_groups) : $si;	// past top-5 → Other
		$bucket_vals[$bi][$si] = ($bucket_vals[$bi][$si] ?? 0) + $gr[2];
	}

	$series_rows = [];

	for ($bi = 0, $b = $from; $b <= $to; $bi++, $b += $g) {
		$v = [];

		for ($i = 0; $i < count($names); $i++) {
			// bps = bucket bytes over the bucket span
			$v[] = (int) round((($bucket_vals[$bi][$i] ?? 0) * 8) / $g);
		}

		$series_rows[] = ['t' => $b, 'v' => $v];
	}

	// donut: top apps + Other (<= 6 segments)
	$donut = [];
	$donut_sum = 0;
	$res = DBselect('SELECT app AS name, COALESCE(SUM(bytes),0)::bigint AS bytes FROM flow_record'.
		$between.' GROUP BY 1 ORDER BY 2 DESC LIMIT 5'
	);

	while ($row = DBfetch($res)) {
		$donut[] = ['name' => $row['name'], 'bytes' => (int) $row['bytes']];
		$donut_sum += (int) $row['bytes'];
	}

	$other = max(0, (int) $kpi['bytes'] - $donut_sum);

	if ($other > 0) {
		$donut[] = ['name' => 'Other', 'bytes' => $other];
	}

	$bytes = (int) $kpi['bytes'];

	return [
		'window' => $window,
		'sort' => $sort,
		'from' => $from,
		'to' => $to,
		'g' => $g,
		'kpi' => [
			'bytes' => $bytes,
			'bps' => $bytes > 0 ? (float) ($bytes * 8 / ($to - $from + $g)) : 0.0,
			'flows' => (int) $kpi['flows'],
			'packets' => (int) $kpi['packets'],
			'exporters' => (int) $kpi['exporters'],
			'countries' => (int) $kpi['countries'],
			'top_app' => ($tables['app'] !== []) ? $tables['app'][0]['name'] : '',
			'top_app_share' => ($tables['app'] !== [] && $bytes > 0)
				? (int) round(100 * $tables['app'][0]['bytes'] / $bytes)
				: 0
		],
		'series' => ['names' => $names, 'rows' => $series_rows],
		'donut' => $donut,
		'tables' => $tables
	];
}

// ---- request handling -------------------------------------------------------

$has_table = flowTableExists();

if ($has_table && getRequest('ajax') === '1' && getRequest('mode') === 'data') {
	$window = getRequest('window', '6h');
	$sort = getRequest('sort', 'volume');
	$window = isset(FLOW_WINDOWS[$window]) ? $window : '6h';
	$sort = in_array($sort, ['volume', 'bytes'], true) ? $sort : 'volume';

	$added = flowTopUp();
	$data = flowData($window, $sort);
	$data['buckets_added'] = $added;

	session_write_close();

	header('Content-Type: application/json; charset=UTF-8');
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS
		| JSON_HEX_AMP | JSON_HEX_QUOT
	);
	exit;
}

$window = getRequest('window', '6h');
$sort = getRequest('sort', 'volume');
$window = isset(FLOW_WINDOWS[$window]) ? $window : '6h';
$sort = in_array($sort, ['volume', 'bytes'], true) ? $sort : 'volume';

$added = 0;
$data = [
	'window' => $window,
	'sort' => $sort,
	'no_table' => !$has_table,
	'kpi' => ['bytes' => 0, 'bps' => 0.0, 'flows' => 0, 'packets' => 0, 'exporters' => 0,
		'countries' => 0, 'top_app' => '', 'top_app_share' => 0],
	'series' => ['names' => [], 'rows' => []],
	'donut' => [],
	'tables' => ['src' => [], 'dst' => [], 'app' => [], 'country' => [], 'city' => [],
		'exporter' => [], 'group' => []]
];

if ($has_table) {
	$added = flowTopUp();
	$data = flowData($window, $sort);
	$data['buckets_added'] = $added;
}

// UI strings for the client-side script, translated by the user's language.
$i18n = [
	'updated' => _('Updated %1$s'),
	'range' => _('Time range'),
	'sort_by' => _('Sort by'),
	'w_1h' => _('Last hour'),
	'w_6h' => _('Last 6 hours'),
	'w_24h' => _('Last 24 hours'),
	'w_7d' => _('Last 7 days'),
	'volume' => _('Traffic volume'),
	'bytes' => _('Byte count'),
	'k_total' => _('Total traffic'),
	'k_rate' => _('Average rate'),
	'k_flows' => _('Flows'),
	'k_packets' => _('Packets'),
	'k_topapp' => _('Top application'),
	'k_exporters' => _('Active exporters'),
	'k_countries' => _('Countries reached'),
	'c_area' => _('Traffic over time'),
	'c_area_sub' => _('by IP group'),
	'c_donut' => _('Share of traffic'),
	'other' => _('Other'),
	'peak' => _('peak %1$s'),
	't_src' => _('Top sources'),
	't_dst' => _('Top destinations'),
	't_app' => _('Top applications'),
	't_country' => _('Top countries'),
	't_city' => _('Top cities'),
	't_exporter' => _('Top exporters'),
	't_group' => _('Top IP groups'),
	't_group_sub' => _('Counted for both endpoints of each flow.'),
	'col_volume' => _('Volume'),
	'col_bytes' => _('Bytes'),
	'col_flows' => _('Flows'),
	'no_data' => _('No data for this range.'),
	'no_table' => _('Demo table not found — run flow_dashboard.sql on the database first.'),
	'conn_lost' => _('Connection lost — retrying.')
];

session_write_close();

header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="<?= str_starts_with(CWebUser::$data['lang'], 'th') ? 'th' : 'en' ?>">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title>Traffic analytics — Zabbix</title>
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
			/* validated categorical slots (dataviz dark set, checked on --surface) */
			--s1: #3987e5;
			--s2: #d95926;
			--s3: #199e70;
			--s4: #c98500;
			--s5: #d55181;
			--sother: #8296b3;
		}

		body { background: var(--bg); margin: 0; font-family: Sarabun, Arial, Tahoma, sans-serif;
			color: var(--text); font-size: 14px; }
		.mono { font-family: ui-monospace, 'Cascadia Code', Consolas, monospace; }

		.fdash { display: flex; flex-direction: column; min-height: 100vh; }

		/* ---- top bar ---- */
		.fdash .topbar { background: linear-gradient(180deg, var(--surface-2), var(--surface));
			border-bottom: 1px solid var(--line); padding: 10px 18px;
			display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
		.fdash .topbar .mark { width: 26px; height: 26px; flex: 0 0 auto; }
		.fdash .topbar h1 { font-size: 17px; margin: 0; font-weight: 700; letter-spacing: .01em; }
		.fdash .topbar .sub { color: var(--text-dim); font-size: 12px; margin-top: 1px; }
		.fdash .topbar .spacer { flex: 1; }
		.fdash .topbar .back { color: var(--accent); text-decoration: none; font-size: 13.5px; }
		.fdash .topbar .back:hover { text-decoration: underline; }
		.fdash .topbar .stamp { color: var(--text-dim); font-size: 12px; }

		/* ---- toolbar (one row above everything it scopes) ---- */
		.fdash .toolbar { display: flex; align-items: center; gap: 18px; flex-wrap: wrap;
			padding: 12px 18px 0; }
		.fdash .toolbar .lbl { color: var(--text-dim); font-size: 12px; }
		.fdash .seg { display: inline-flex; border: 1px solid var(--line); border-radius: 8px;
			overflow: hidden; background: var(--surface); }
		.fdash .seg button { appearance: none; border: 0; background: transparent; color: var(--text-dim);
			font: inherit; font-size: 13px; padding: 6px 13px; cursor: pointer; }
		.fdash .seg button + button { border-left: 1px solid var(--line-soft); }
		.fdash .seg button:hover { color: var(--text); background: var(--surface-2); }
		.fdash .seg button.is-on { color: #fff; background: var(--accent-soft); font-weight: 600; }
		.fdash .seg button:focus-visible { outline: 2px solid var(--accent); outline-offset: -2px; }
		.fdash .autohint { color: var(--text-dim); font-size: 12px; margin-left: auto; }
		.fdash .autohint .dot { display: inline-block; width: 7px; height: 7px; border-radius: 50%;
			background: var(--ok); margin-right: 5px; vertical-align: 1px; }

		/* ---- KPI stat tiles ---- */
		.fdash .kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
			gap: 12px; padding: 12px 18px 0; }
		.fdash .kpi { background: var(--surface); border: 1px solid var(--line-soft);
			border-radius: 10px; padding: 10px 14px 9px; }
		.fdash .kpi .lbl { color: var(--text-dim); font-size: 12px; }
		.fdash .kpi .val { font-size: 22px; font-weight: 600; margin-top: 2px; line-height: 1.2; }
		.fdash .kpi .sub { color: var(--text-dim); font-size: 12px; margin-top: 2px;
			overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

		/* ---- charts row ---- */
		.fdash .charts { display: grid; grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr);
			gap: 12px; padding: 12px 18px 0; }
		@media (max-width: 900px) { .fdash .charts { grid-template-columns: minmax(0, 1fr); } }
		.fdash .card { background: var(--surface); border: 1px solid var(--line-soft);
			border-radius: 10px; padding: 12px 14px; position: relative; }
		.fdash .card h3 { margin: 0; font-size: 14px; font-weight: 700; }
		.fdash .card .csub { color: var(--text-dim); font-size: 12px; margin-top: 1px; }
		.fdash .chartbox { position: relative; margin-top: 8px; }
		.fdash .chartbox svg { display: block; width: 100%; height: auto; }
		.fdash .tip { position: absolute; pointer-events: none; background: var(--surface-2);
			border: 1px solid var(--line); border-radius: 8px; padding: 7px 10px;
			font-size: 12px; box-shadow: 0 6px 18px rgba(0, 0, 0, .4); display: none;
			min-width: 150px; z-index: 5; }
		.fdash .tip .tt { color: var(--text-dim); margin-bottom: 4px; }
		.fdash .tip .tr { display: flex; align-items: center; gap: 7px; padding: 1px 0; }
		.fdash .tip .key { width: 10px; height: 2px; flex: 0 0 auto; border-radius: 1px; }
		.fdash .tip .tv { font-weight: 600; font-variant-numeric: tabular-nums; margin-left: auto;
			padding-left: 12px; }
		.fdash .tip .tn { color: var(--text-dim); overflow: hidden; text-overflow: ellipsis;
			white-space: nowrap; max-width: 150px; }
		.fdash .legend { display: flex; flex-wrap: wrap; gap: 4px 14px; margin-top: 8px; }
		.fdash .legend span { color: var(--text-dim); font-size: 12px; display: inline-flex;
			align-items: center; gap: 6px; }
		.fdash .legend .sw { width: 10px; height: 10px; border-radius: 3px; flex: 0 0 auto; }

		/* donut card layout: chart left, HTML legend right */
		.fdash .donutwrap { display: flex; align-items: center; gap: 14px; margin-top: 8px; }
		.fdash .donutwrap svg { width: 190px; height: 190px; flex: 0 0 auto; }
		.fdash .dlegend { display: flex; flex-direction: column; gap: 6px; min-width: 0; flex: 1; }
		.fdash .dlegend .dl { display: grid; grid-template-columns: 12px minmax(0, 1fr) auto;
			gap: 7px; align-items: center; font-size: 12.5px; }
		.fdash .dlegend .sw { width: 10px; height: 10px; border-radius: 3px; }
		.fdash .dlegend .dn { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
		.fdash .dlegend .dv { color: var(--text-dim); font-variant-numeric: tabular-nums; }

		/* ---- top-10 tables ---- */
		.fdash .tables { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
			gap: 12px; padding: 12px 18px 18px; }
		.fdash .tcard { background: var(--surface); border: 1px solid var(--line-soft);
			border-radius: 10px; padding: 12px 14px 8px; }
		.fdash .tcard h3 { margin: 0; font-size: 14px; font-weight: 700; }
		.fdash .tcard .csub { color: var(--text-dim); font-size: 12px; margin-top: 1px; }
		.fdash .thead, .fdash .trow { display: grid;
			grid-template-columns: 24px minmax(0, 1fr) 78px 78px 46px;
			gap: 10px; align-items: center; }
		.fdash .thead { color: var(--text-dim); font-size: 11.5px; padding: 9px 2px 5px;
			border-bottom: 1px solid var(--line-soft); }
		.fdash .thead .num, .fdash .thead .cval, .fdash .thead .cflow { text-align: right; }
		.fdash .thead .cval.is-on { color: var(--accent); font-weight: 700; }
		.fdash .trow { padding: 6px 2px; border-bottom: 1px solid var(--line-soft); }
		.fdash .trow:last-child { border-bottom: 0; }
		.fdash .trow .rank { color: var(--text-dim); font-variant-numeric: tabular-nums;
			font-size: 12px; }
		.fdash .trow .rank.is-top { color: var(--accent); font-weight: 700; }
		.fdash .trow .nm { min-width: 0; }
		.fdash .trow .nm .n { font-weight: 600; overflow: hidden; text-overflow: ellipsis;
			white-space: nowrap; }
		.fdash .trow .nm .s { color: var(--text-dim); font-size: 11.5px; overflow: hidden;
			text-overflow: ellipsis; white-space: nowrap; }
		.fdash .trow .nm .bar { height: 4px; border-radius: 2px; background: var(--s1);
			margin-top: 4px; opacity: .85; }
		.fdash .trow .v { text-align: right; font-variant-numeric: tabular-nums; font-size: 12.5px; }
		.fdash .trow .v.dim { color: var(--text-dim); }
		.fdash .trow .v.on { font-weight: 600; }
		.fdash .trow .fl { text-align: right; color: var(--text-dim);
			font-variant-numeric: tabular-nums; font-size: 12px; }
		.fdash .tnone { color: var(--text-dim); padding: 12px 2px; font-size: 13px; }

		.fdash .empty { margin: 60px 18px; text-align: center; color: var(--text-dim); }

		/* refetch keeps the frame: hold the previous render, dimmed */
		.fdash .dim .kpis, .fdash .dim .charts, .fdash .dim .tables { opacity: .55;
			transition: opacity .15s; }

		/* ---- toast ---- */
		#toast { position: fixed; left: 50%; bottom: 26px; transform: translateX(-50%);
			background: var(--surface-2); color: var(--text); border: 1px solid var(--line);
			border-radius: 8px; padding: 8px 14px; font-size: 13px; opacity: 0;
			pointer-events: none; transition: opacity .2s; z-index: 20; }
		#toast.is-on { opacity: 1; }

		@media (prefers-reduced-motion: reduce) {
			.fdash * { transition: none !important; }
		}
	</style>
</head>
<body>
<div class="fdash" id="root">
	<div class="topbar">
		<svg class="mark" viewBox="0 0 24 24" aria-hidden="true"><path fill="#3fa2ff"
			d="M3 13h4l2-6 3 12 3-9 2 3h4" stroke="#3fa2ff" stroke-width="1.8" fill="none"
			stroke-linecap="round" stroke-linejoin="round"/></svg>
		<div>
			<h1><?= _('Traffic analytics') ?></h1>
			<div class="sub"><?= _('Synthetic demo traffic — Top-10 rankings, trends and shares.') ?></div>
		</div>
		<div class="spacer"></div>
		<span class="stamp" id="stamp"></span>
		<a class="back" href="zabbix.php"><?= _('← Back to main menu') ?></a>
	</div>

	<?php if ($data['no_table']): ?>
	<div class="empty"><?= _('Demo table not found — run flow_dashboard.sql on the database first.') ?></div>
	<?php else: ?>
	<div class="toolbar">
		<span class="lbl"><?= _('Time range') ?></span>
		<div class="seg" id="seg-range" role="group" aria-label="<?= _('Time range') ?>">
			<button type="button" data-w="1h"><?= _('Last hour') ?></button>
			<button type="button" data-w="6h"><?= _('Last 6 hours') ?></button>
			<button type="button" data-w="24h"><?= _('Last 24 hours') ?></button>
			<button type="button" data-w="7d"><?= _('Last 7 days') ?></button>
		</div>
		<span class="lbl"><?= _('Sort by') ?></span>
		<div class="seg" id="seg-sort" role="group" aria-label="<?= _('Sort by') ?>">
			<button type="button" data-s="volume"><?= _('Traffic volume') ?></button>
			<button type="button" data-s="bytes"><?= _('Byte count') ?></button>
		</div>
		<span class="autohint"><span class="dot"></span>60 s</span>
	</div>

	<div class="kpis" id="kpis"></div>

	<div class="charts">
		<div class="card">
			<h3><?= _('Traffic over time') ?></h3>
			<div class="csub"><?= _('by IP group') ?></div>
			<div class="chartbox" id="areabox">
				<svg id="areachart" viewBox="0 0 900 300" preserveAspectRatio="xMidYMid meet"
					role="img" aria-label=""></svg>
				<div class="tip" id="areatip"></div>
			</div>
			<div class="legend" id="arealegend"></div>
		</div>
		<div class="card">
			<h3><?= _('Share of traffic') ?></h3>
			<div class="csub"><?= _('Top applications') ?></div>
			<div class="donutwrap">
				<svg id="donut" viewBox="0 0 200 200" role="img" aria-label=""></svg>
				<div class="dlegend" id="dlegend"></div>
			</div>
		</div>
	</div>

	<div class="tables" id="tables">
		<?php foreach ([
			'src' => _('Top sources'),
			'dst' => _('Top destinations'),
			'app' => _('Top applications'),
			'country' => _('Top countries'),
			'city' => _('Top cities'),
			'exporter' => _('Top exporters'),
			'group' => _('Top IP groups')
		] as $tkey => $ttitle): ?>
		<div class="tcard" data-table="<?= $tkey ?>">
			<h3><?= $ttitle ?></h3>
			<?php if ($tkey === 'group'): ?>
			<div class="csub"><?= _('Counted for both endpoints of each flow.') ?></div>
			<?php endif; ?>
			<div class="thead">
				<span class="num">#</span>
				<span></span>
				<span class="cval" data-col="volume"><?= _('Volume') ?></span>
				<span class="cval" data-col="bytes"><?= _('Bytes') ?></span>
				<span class="cflow"><?= _('Flows') ?></span>
			</div>
			<div class="tbody"></div>
		</div>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>
</div>

<div id="toast" role="status"></div>

<script>
const DATA = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS
	| JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const T = <?= json_encode($i18n, JSON_UNESCAPED_UNICODE) ?>;

// validated categorical slots (see the :root block); "Other" is the de-emphasis gray
const SERIES_COLORS = ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#8296b3'];
const TH_UI = document.documentElement.lang === 'th';
const MOTION = matchMedia('(prefers-reduced-motion: no-preference)').matches;

const COUNTRY = {
	US: {en: 'United States', th: 'สหรัฐอเมริกา', flag: '\u{1F1FA}\u{1F1F8}'},
	SG: {en: 'Singapore', th: 'สิงคโปร์', flag: '\u{1F1F8}\u{1F1EC}'},
	JP: {en: 'Japan', th: 'ญี่ปุ่น', flag: '\u{1F1EF}\u{1F1F5}'},
	HK: {en: 'Hong Kong', th: 'ฮ่องกง', flag: '\u{1F1ED}\u{1F1F0}'},
	KR: {en: 'South Korea', th: 'เกาหลีใต้', flag: '\u{1F1F0}\u{1F1F7}'},
	DE: {en: 'Germany', th: 'เยอรมนี', flag: '\u{1F1E9}\u{1F1EA}'},
	IN: {en: 'India', th: 'อินเดีย', flag: '\u{1F1EE}\u{1F1F3}'},
	AU: {en: 'Australia', th: 'ออสเตรเลีย', flag: '\u{1F1E6}\u{1F1FA}'},
	NL: {en: 'Netherlands', th: 'เนเธอร์แลนด์', flag: '\u{1F1F3}\u{1F1F1}'},
	TH: {en: 'Thailand', th: 'ไทย', flag: '\u{1F1F9}\u{1F1ED}'},
	CN: {en: 'China', th: 'จีน', flag: '\u{1F1E8}\u{1F1F3}'}
};

let state = {window: DATA.window, sort: DATA.sort};
let inflight = false;

const escHtml = s => String(s).replace(/[&<>"']/g,
	c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

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

function fmtBytes(v) {
	if (!isFinite(v) || v <= 0) return '0 B';

	const units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
	let u = 0;

	while (v >= 1000 && u < units.length - 1) {
		v /= 1000;
		u++;
	}

	return v.toFixed(v < 10 && u > 0 ? (v < 100 ? 1 : 0) : 0) + ' ' + units[u];
}

// compact counts for KPI tiles: 1,284 / 12.9K / 4.2M
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

const countryLabel = cc => {
	const c = COUNTRY[cc];

	return c ? (c.flag + ' ' + (TH_UI ? c.th : c.en)) : cc;
};

// the server folds the tail into a literal "Other" bucket — show it localized
const displayName = nm => nm === 'Other' ? T.other : nm;

// ---- KPI tiles ---------------------------------------------------------------

function renderKpis(d) {
	const box = document.getElementById('kpis');
	box.textContent = '';

	const tiles = [
		[T.k_total, fmtBytes(d.kpi.bytes), fmtBps(d.kpi.bps)],
		[T.k_rate, fmtBps(d.kpi.bps), T.peak.replace('%1$s', fmtBps(peakBps(d)))],
		[T.k_flows, fmtNum(d.kpi.flows), ''],
		[T.k_packets, fmtNum(d.kpi.packets), ''],
		[T.k_topapp, d.kpi.top_app || '—', d.kpi.top_app_share + '%'],
		[T.k_exporters, String(d.kpi.exporters), T.k_countries + ': ' + d.kpi.countries]
	];

	for (const [lbl, val, sub] of tiles) {
		const tile = document.createElement('div');
		tile.className = 'kpi';

		const l = document.createElement('div');
		l.className = 'lbl';
		l.textContent = lbl;

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

function peakBps(d) {
	let peak = 0;

	for (const row of d.series.rows) {
		const tot = row.v.reduce((a, b) => a + b, 0);

		if (tot > peak) peak = tot;
	}

	return peak;
}

// ---- stacked area chart ------------------------------------------------------

function renderArea(d) {
	const svg = document.getElementById('areachart');
	const rows = d.series.rows;
	const names = d.series.names;

	svg.querySelector('g.plot')?.remove();
	svg.querySelector('rect.hit')?.remove();
	svg.querySelector('line.cross')?.remove();

	if (!rows.length) {
		svg.setAttribute('aria-label', T.no_data);
		document.getElementById('arealegend').textContent = '';

		return;
	}

	const W = 900, H = 300, M = {l: 58, r: 14, t: 12, b: 28};
	const pw = W - M.l - M.r, ph = H - M.t - M.b;

	// y-max: nice-rounded maximum of the stacked totals
	let ymax = 0;

	for (const row of rows) {
		const tot = row.v.reduce((a, b) => a + b, 0);

		if (tot > ymax) ymax = tot;
	}

	ymax = niceCeil(Math.max(1, ymax));
	const t0 = d.from, tg = d.g;
	const x = t => M.l + pw * (t - t0) / Math.max(1, rows[rows.length - 1].t - t0);
	const y = v => M.t + ph * (1 - v / ymax);

	// cumulative boundaries per bucket
	const cum = rows.map(row => {
		let acc = 0;

		return row.v.map(v => (acc += v));
	});

	let g = '<g class="plot">';

	// hairline gridlines + y ticks (clean numbers, text tokens)
	for (let i = 0; i <= 4; i++) {
		const val = ymax * i / 4;
		const yy = y(val);

		g += '<line x1="'+M.l+'" y1="'+yy+'" x2="'+(W-M.r)+'" y2="'+yy+
			'" stroke="#1a2942" stroke-width="1"/>';
		g += '<text x="'+(M.l-8)+'" y="'+(yy+4)+'" text-anchor="end" font-size="11" '+
			'fill="#8296b3" style="font-variant-numeric:tabular-nums">'+fmtBps(val)+'</text>';
	}

	// x ticks: 5-6 readable positions across the window
	const span = rows[rows.length - 1].t - t0;
	const tickn = 5;

	for (let i = 0; i <= tickn; i++) {
		const t = t0 + Math.round(span * i / tickn);
		const xx = x(t);

		g += '<line x1="'+xx+'" y1="'+(M.t+ph)+'" x2="'+xx+'" y2="'+(M.t+ph+4)+
			'" stroke="#24344f" stroke-width="1"/>';
		g += '<text x="'+xx+'" y="'+(M.t+ph+18)+'" text-anchor="middle" font-size="11" '+
			'fill="#8296b3">'+(d.window === '7d' ? fmtDay(t) : fmtTime(t))+'</text>';
	}

	// stacked bands: wash fill + 2px top-edge line per series
	for (let s = 0; s < names.length; s++) {
		let up = '', dn = '';

		for (let i = 0; i < rows.length; i++) {
			up += (i ? 'L' : 'M') + x(rows[i].t).toFixed(1) + ' ' + y(cum[i][s]).toFixed(1) + ' ';
		}

		for (let i = rows.length - 1; i >= 0; i--) {
			const below = s ? cum[i][s - 1] : 0;

			dn += 'L' + x(rows[i].t).toFixed(1) + ' ' + y(below).toFixed(1) + ' ';
		}

		g += '<path d="'+up+dn+'Z" fill="'+SERIES_COLORS[s]+'" fill-opacity=".12"/>';
		g += '<path d="'+up+'" fill="none" stroke="'+SERIES_COLORS[s]+'" stroke-width="2" '+
			'stroke-linejoin="round" stroke-linecap="round"/>';
	}

	// baseline
	g += '<line x1="'+M.l+'" y1="'+(M.t+ph)+'" x2="'+(W-M.r)+'" y2="'+(M.t+ph)+
		'" stroke="#24344f" stroke-width="1"/>';
	g += '</g>';
	svg.insertAdjacentHTML('beforeend', g);

	// legend (always present for >= 2 series)
	const legend = document.getElementById('arealegend');
	legend.textContent = '';

	names.forEach((nm, i) => {
		const sp = document.createElement('span');
		const sw = document.createElement('span');
		sw.className = 'sw';
		sw.style.background = SERIES_COLORS[i];
		sp.append(sw, document.createTextNode(displayName(nm)));
		legend.append(sp);
	});

	// hover layer: crosshair finds the X, one tooltip lists every series
	svg.setAttribute('aria-label', T.c_area + ' (' + T.c_area_sub + '), ' +
		T.peak.replace('%1$s', fmtBps(peakBps(d))));

	attachAreaHover(svg, rows, cum, names, x, y, M, W, H, tg);
}

// round up to a clean 1/2/5-decade value for the y axis
function niceCeil(v) {
	const e = Math.pow(10, Math.floor(Math.log10(v)));

	for (const m of [1, 2, 2.5, 5, 10]) {
		if (m * e >= v) return m * e;
	}

	return 10 * e;
}

function attachAreaHover(svg, rows, cum, names, x, y, M, W, H, tg) {
	const box = document.getElementById('areabox');
	const tip = document.getElementById('areatip');

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
		const t = rows[idx].t;

		guide.setAttribute('x1', x(t));
		guide.setAttribute('x2', x(t));
		guide.style.display = '';

		// tooltip: values lead, labels follow; line keys carry identity
		tip.textContent = '';

		const tt = document.createElement('div');
		tt.className = 'tt';
		tt.textContent = fmtDay(t);
		tip.append(tt);

		for (let s = names.length - 1; s >= 0; s--) {
			const tr = document.createElement('div');
			tr.className = 'tr';

			const key = document.createElement('span');
			key.className = 'key';
			key.style.background = SERIES_COLORS[s];

			const tv = document.createElement('span');
			tv.className = 'tv';
			tv.textContent = fmtBps(rows[idx].v[s]);

			const tn = document.createElement('span');
			tn.className = 'tn';
			tn.textContent = displayName(names[s]);

			tr.append(key, tn, tv);
			tip.append(tr);
		}

		tip.style.display = 'block';

		// keep the tooltip inside the card
		const boxr = box.getBoundingClientRect();
		const px = x(t) / W * boxr.width;
		tip.style.left = Math.min(boxr.width - 180, Math.max(0, px + 12)) + 'px';
		tip.style.top = '8px';
	};

	const hide = () => {
		idx = -1;
		guide.style.display = 'none';
		tip.style.display = 'none';
	};

	rect.addEventListener('pointermove', e => {
		const boxr = box.getBoundingClientRect();
		const px = (e.clientX - boxr.left) / boxr.width * W;
		const t = tOfX(px, rows, M, W);
		const i = nearestIndex(t, rows);

		if (i !== idx) show(i);
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

function tOfX(px, rows, M, W) {
	// invert the linear x scale
	const t0 = rows[0].t, t1 = rows[rows.length - 1].t;
	const x0 = M.l, x1 = M.l + (W - M.l - M.r);

	return t0 + (t1 - t0) * (px - x0) / Math.max(1, x1 - x0);
}

function nearestIndex(t, rows) {
	let best = 0, bd = Infinity;

	for (let i = 0; i < rows.length; i++) {
		const dd = Math.abs(rows[i].t - t);

		if (dd < bd) {
			bd = dd;
			best = i;
		}
	}

	return best;
}

// ---- donut -------------------------------------------------------------------

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

	if (!d.donut.length) {
		svg.setAttribute('aria-label', T.no_data);

		return;
	}

	const total = d.donut.reduce((a, s) => a + s.bytes, 0);
	const CX = 100, CY = 100, R = 78, W = 26;
	const full = total > 0;

	let a = 0;
	const slices = [];

	d.donut.forEach((s, i) => {
		const frac = full ? s.bytes / total : 0;
		const a1 = a + frac * 360;
		slices.push([i, a, a1, frac]);
		a = a1;
	});

	// 2px surface gaps between segments: draw each slice as a stroked arc
	// slightly narrower than the ring so the surface shows through
	for (const [i, a0, a1] of slices) {
		if (a1 - a0 <= 0.5) continue;

		const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
		path.setAttribute('d', ARC.arcPath(CX, CY, R - W / 2, a0 + 1.2, Math.max(a0 + 1.3, a1 - 1.2)));
		path.setAttribute('fill', 'none');
		path.setAttribute('stroke', SERIES_COLORS[i]);
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
	t1.textContent = fmtBytes(total);

	const t2 = document.createElementNS('http://www.w3.org/2000/svg', 'text');
	t2.setAttribute('x', CX);
	t2.setAttribute('y', CY + 16);
	t2.setAttribute('text-anchor', 'middle');
	t2.setAttribute('font-size', '11');
	t2.setAttribute('fill', '#8296b3');
	t2.textContent = fmtBps(d.kpi.bps);

	svg.append(t1, t2);

	svg.setAttribute('aria-label', T.c_donut + ': ' +
		d.donut.slice(0, 3).map(s => displayName(s.name) + ' ' +
			Math.round(100 * s.bytes / Math.max(1, total)) + '%').join(', '));

	// HTML legend rows (values readable without hover)
	for (const [i, , , frac] of slices) {
		const s = d.donut[i];
		const row = document.createElement('div');
		row.className = 'dl';

		const sw = document.createElement('span');
		sw.className = 'sw';
		sw.style.background = SERIES_COLORS[i];

		const nm = document.createElement('span');
		nm.className = 'dn';
		nm.textContent = displayName(s.name);

		const dv = document.createElement('span');
		dv.className = 'dv';
		dv.textContent = Math.round(frac * 100) + '% · ' + fmtBytes(s.bytes);

		row.append(sw, nm, dv);
		legend.append(row);
	}
}

// ---- top-10 tables -----------------------------------------------------------

function renderTables(d) {
	const root = document.getElementById('tables');

	root.querySelectorAll('.cval').forEach(el => {
		el.classList.toggle('is-on', el.dataset.col === d.sort);
	});

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

		const max = Math.max(...rows.map(r => d.sort === 'bytes' ? r.bytes : r.bps), 1);

		rows.forEach((r, i) => {
			const row = document.createElement('div');
			row.className = 'trow';

			const rank = document.createElement('span');
			rank.className = 'rank' + (i < 3 ? ' is-top' : '');
			rank.textContent = String(i + 1);

			const nm = document.createElement('span');
			nm.className = 'nm';

			const n = document.createElement('div');
			n.className = 'n';
			n.textContent = (key === 'country') ? countryLabel(r.name) : r.name;

			const s = document.createElement('div');
			s.className = 's';
			s.textContent = (key === 'country') ? r.name
				: ((key === 'city' && r.cc) ? countryLabel(r.cc) : r.sub);

			const bar = document.createElement('div');
			bar.className = 'bar';
			bar.style.width = Math.max(2, Math.round(100 * (d.sort === 'bytes' ? r.bytes : r.bps) / max)) + '%';

			nm.append(n, s, bar);

			const v1 = document.createElement('span');
			v1.className = 'v on';
			v1.textContent = d.sort === 'bytes' ? fmtBytes(r.bytes) : fmtBps(r.bps);

			const v2 = document.createElement('span');
			v2.className = 'v dim';
			v2.textContent = d.sort === 'bytes' ? fmtBps(r.bps) : fmtBytes(r.bytes);

			const fl = document.createElement('span');
			fl.className = 'fl';
			fl.textContent = fmtNum(r.flows);

			row.append(rank, nm, v1, v2, fl);
			body.append(row);
		});
	}
}

// ---- plumbing ----------------------------------------------------------------

function stamp(d) {
	document.getElementById('stamp').textContent =
		T.updated.replace('%1$s', new Date().toLocaleTimeString(TH_UI ? 'th-TH' : 'en-GB', {hour12: false}));
}

function buildAll(d) {
	document.getElementById('root').classList.remove('dim');
	renderKpis(d);
	renderArea(d);
	renderDonut(d);
	renderTables(d);
	stamp(d);
}

async function refresh(silent) {
	if (inflight) return;
	inflight = true;

	document.getElementById('root').classList.add('dim');

	try {
		const url = 'flow_dashboard.php?' + new URLSearchParams({
			ajax: '1', mode: 'data', window: state.window, sort: state.sort
		});
		const res = await fetch(url);
		const json = await res.json();

		if (json && json.kpi) {
			state = {window: json.window, sort: json.sort};
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

// toolbar: one row above the charts, scopes everything below
for (const btn of document.querySelectorAll('#seg-range button')) {
	btn.addEventListener('click', () => {
		state.window = btn.dataset.w;
		markSeg('seg-range', btn);
		refresh(true);
	});
}

for (const btn of document.querySelectorAll('#seg-sort button')) {
	btn.addEventListener('click', () => {
		state.sort = btn.dataset.s;
		markSeg('seg-sort', btn);
		refresh(true);
	});
}

function markSeg(id, btn) {
	for (const b of document.getElementById(id).querySelectorAll('button')) {
		b.classList.toggle('is-on', b === btn);
	}
}

markSeg('seg-range', document.querySelector('#seg-range button[data-w="'+state.window+'"]'));
markSeg('seg-sort', document.querySelector('#seg-sort button[data-s="'+state.sort+'"]'));

if (!DATA.no_table) {
	buildAll(DATA);

	// auto-refresh every 60 s (also advances the demo dataset server-side)
	setInterval(() => refresh(true), 60000);
}
</script>
</body>
</html>
