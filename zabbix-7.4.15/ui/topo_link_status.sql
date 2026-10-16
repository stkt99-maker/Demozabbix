-- Live link status for the hybrid topology page (topo_tree.php).
-- topo_link gains interface bindings (ifa / ifb — the net.if.* interface name
-- on each end's host) and capacity (mbps, 0 = unknown) so the canvas can
-- colour links by bandwidth utilization and flash red when a bound host or
-- interface is down.
--
-- Run as postgres (topo_link itself must exist already), then ownership stays
-- with the frontend role (zabbix) — the UI connects as zabbix and needs full
-- DML rights.

ALTER TABLE topo_link ADD COLUMN IF NOT EXISTS ifa  varchar(64) NOT NULL DEFAULT '';
ALTER TABLE topo_link ADD COLUMN IF NOT EXISTS ifb  varchar(64) NOT NULL DEFAULT '';
ALTER TABLE topo_link ADD COLUMN IF NOT EXISTS mbps integer  NOT NULL DEFAULT 0;

ALTER TABLE topo_link OWNER TO zabbix;
