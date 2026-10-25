#!/usr/bin/env bash
# Install server endpoints and SQL privileges; does not activate any machine.
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root" >&2; exit 1; }
cd /opt/devmcp-admin
php -l public/agent-job-poll.php
php -l public/agent-job-result.php
php -l bin/remote-job-policy.php
php tests/distributed/remote-readonly-smoke.php
mariadb devmcp_distributed < database/migrations/005_remote_readonly_jobs.sql
mariadb -e "GRANT SELECT (id, status) ON devmcp_distributed.dm_machine TO 'devmcp_writer'@'localhost'; GRANT SELECT (id, machine_id, workspace_path, enabled) ON devmcp_distributed.dm_deployment TO 'devmcp_writer'@'localhost'; GRANT SELECT (id, deployment_id, machine_id, action_name, arguments_json, state, created_at), UPDATE (id, state, claimed_at, finished_at, result_json) ON devmcp_distributed.dm_remote_readonly_job TO 'devmcp_writer'@'localhost';"
CONF=/etc/nginx/sites-available/devmcp-admin.conf
[[ -f "$CONF" ]] || { echo "Nginx config missing" >&2; exit 1; }
if ! grep -Fq 'location = /v1/agent/job-result {' "$CONF"; then
python3 - "$CONF" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
needle='    location = /v1/agent/job-poll {'
if needle not in s:
    raise SystemExit('Job poll route not installed')
block='''    location = /v1/agent/job-result {
        auth_basic off;
        limit_except POST { deny all; }
        client_max_body_size 70k;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /opt/devmcp-admin/public/agent-job-result.php;
        fastcgi_param HTTPS on;
        fastcgi_param DEVMCP_DISTRIBUTED_CONFIG /etc/devmcp-admin/distributed.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
'''
p.write_text(s.replace(needle,block+needle,1))
PY
fi
nginx -t
systemctl reload nginx
echo "PASS: server job endpoints installed; execution remains gated"
