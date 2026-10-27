#!/usr/bin/env bash
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root" >&2; exit 1; }
cd /opt/devmcp-admin
php -l public/distributed-admin.php
php -l bin/remote-job-enqueue.php
php -l bin/distributed-agent.php
php -l bin/remote-local-policy.php
php tests/distributed/remote-readonly-smoke.php
mariadb -e "GRANT INSERT (id, deployment_id, machine_id, action_name, arguments_json, state) ON devmcp_distributed.dm_remote_readonly_job TO 'devmcp_writer'@'localhost';"
echo "PASS: admin queue writer permissions installed"
