#!/usr/bin/env bash
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root" >&2; exit 1; }
cd /opt/devmcp-admin
php -l src/Mcp/RemoteTools.php
php -l src/Mcp/ServerFactory.php
php -l bin/devmcp
php -l public/index.php
mariadb -e "
GRANT SELECT (id, name, status) ON devmcp_distributed.dm_machine TO 'devmcp_writer'@'localhost';
GRANT SELECT (id, project_id, machine_id, workspace_path, enabled) ON devmcp_distributed.dm_deployment TO 'devmcp_writer'@'localhost';
GRANT SELECT (id) ON devmcp_distributed.dm_project TO 'devmcp_writer'@'localhost';
GRANT SELECT (id, deployment_id, action_name, state, created_at, claimed_at, finished_at, result_json) ON devmcp_distributed.dm_remote_readonly_job TO 'devmcp_writer'@'localhost';
"
SOURCE=/etc/devmcp-admin/distributed.php
DEST=/etc/devmcp/mcp-distributed.php
[[ -f "$SOURCE" ]] || { echo "Missing distributed admin config" >&2; exit 1; }
getent group devmcp >/dev/null || { echo "Missing devmcp group" >&2; exit 1; }
install -d -m 750 -o root -g devmcp /etc/devmcp
TMP=$(mktemp /etc/devmcp/.mcp-distributed.XXXXXX)
trap 'rm -f "$TMP"' EXIT
php -r '
$c = require $argv[1];
foreach (["dsn", "writer_username", "writer_password"] as $key) {
    if (!isset($c[$key]) || !is_string($c[$key])) { exit(2); }
}
echo "<?php", PHP_EOL, "return ", var_export(array_intersect_key($c, array_flip(["dsn", "writer_username", "writer_password", "remote_write"])), true), ";", PHP_EOL;
' "$SOURCE" > "$TMP"
php -l "$TMP"
php -r '$c = require $argv[1]; if (!is_array($c) || empty($c["dsn"])) exit(2);' "$TMP" >/dev/null
chown root:devmcp "$TMP"
chmod 640 "$TMP"
mv -f "$TMP" "$DEST"
sudo -u devmcp php -r '$c = require $argv[1]; if (!is_array($c) || empty($c["dsn"])) exit(2);' "$DEST" >/dev/null
echo "PASS: remote MCP read-only queue integration configured. Restart MCP tunnel/client to reload tools."
