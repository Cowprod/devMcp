-- Apply as a MariaDB administrator during distributed server provisioning.
-- Minimal privilege needed by POST /v1/agent/ping.
-- Do not grant unrestricted UPDATE on dm_machine.
GRANT UPDATE (last_seen_at)
ON devmcp_distributed.dm_machine
TO 'devmcp_writer'@'localhost';
-- Verification:
SHOW GRANTS FOR 'devmcp_writer'@'localhost';
