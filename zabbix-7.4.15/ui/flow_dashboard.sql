-- Demo traffic records for the flow analytics dashboard (flow_dashboard.php).
--
-- The page is the single writer: a deterministic per-bucket generator tops the
-- table up to the current 5-minute bucket on every render (advisory-locked,
-- ON CONFLICT DO NOTHING idempotent), so deploying is just this DDL.
--
-- bstart is epoch seconds (UTC integer math, no timestamptz): bucket boundaries
-- must not depend on the PHP or PostgreSQL time zone settings. Diurnal weights
-- add a fixed +7h offset (Asia/Bangkok, no DST) when picking the hour.
--
-- PRIMARY KEY (bstart, seq) is both the top-up idempotency key and the
-- leftmost-prefix range index every window query scans.

CREATE TABLE IF NOT EXISTS flow_record (
	bstart      bigint      NOT NULL,
	seq         integer     NOT NULL,
	dur         integer     NOT NULL,            -- flow duration, seconds
	dir         varchar(3)  NOT NULL,            -- 'out' | 'in' | 'int'
	src_ip      varchar(45) NOT NULL DEFAULT '',
	dst_ip      varchar(45) NOT NULL DEFAULT '',
	src_name    varchar(128) NOT NULL DEFAULT '',
	dst_name    varchar(128) NOT NULL DEFAULT '',
	src_country varchar(2)  NOT NULL DEFAULT '', -- ISO-2, '' for the internal side
	dst_country varchar(2)  NOT NULL DEFAULT '',
	src_city    varchar(64) NOT NULL DEFAULT '',
	dst_city    varchar(64) NOT NULL DEFAULT '',
	src_group   varchar(64) NOT NULL DEFAULT '', -- IP group names
	dst_group   varchar(64) NOT NULL DEFAULT '',
	ext_country varchar(2),                      -- remote (external) side; NULL when dir='int'
	ext_city    varchar(64),
	app         varchar(64) NOT NULL,
	proto       varchar(12) NOT NULL DEFAULT 'TCP',
	exporter    varchar(64) NOT NULL,            -- device that exported the flow
	bytes       bigint      NOT NULL,
	packets     bigint      NOT NULL,
	PRIMARY KEY (bstart, seq)
);

ALTER TABLE flow_record OWNER TO zabbix;
