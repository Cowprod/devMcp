#!/usr/bin/env bash
# Install the read-only remote-job schema and polling route on touchdeck.
# Polling remains fail-closed: this does NOT enable remote execution.
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root" >&2; exit 1; }
cd /opt/devmcp-admin
php -l public/agent-job-poll.php
php -l bin/remote-job-policy.php
php -l bin/remote-readonly-actions.php
php tests/distributed/remote-readonly-smoke.php
mariadb devmcp_distributed < database/migrations/005_remote_readonly_jobs.sql
CONF=/etc/nginx/sites-available/devmcp-admin.conf
[[ -f "$CONF" ]] || { echo "Missing nginx site" >&2; exit 1; }
if ! grep -Fq 'location = /v1/agent/job-poll {' "$CONF"; then
  python3 - "$CONF" <<'PY'
import pathlib,sys
p=pathlib.Path(sys.argv[1])
s=p.read_text()
needle='    location = /v1/agent/ping {'
if needle not in s:
    raise SystemExit('Existing ping location not found')
block='''    location = /v1/agent/job-poll {
        auth_basic off;
        limit_except POST { deny all; }
        client_max_body_size 2k;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /opt/devmcp-admin/public/agent-job-poll.php;
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
echo "PASS: remote job schema and fail-closed poll endpoint installed"
