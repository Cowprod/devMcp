#!/usr/bin/env bash
# Run on touchdeck after syncing devmcp-admin. Requires root.
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run with sudo" >&2; exit 1; }
cd /opt/devmcp-admin
php -l public/agent-ping.php
php -l public/agent-workspace-report.php
php -l public/distributed-admin.php
php -l bin/distributed-agent.php
php -l bin/inspect-workspace.php
php -l src/Distributed/Registry/RegistryRepository.php
php tests/distributed/workspace-inspector-smoke.php
mariadb devmcp_distributed < database/migrations/004_workspace_diagnostic.sql
mariadb < deploy/distributed/grant-workspace-diagnostic.sql
CONF=/etc/nginx/sites-available/devmcp-admin.conf
[[ -f "$CONF" ]] || { echo "Missing $CONF" >&2; exit 1; }
if ! grep -Fq 'location = /v1/agent/workspace-report {' "$CONF"; then
  python3 - "$CONF" <<'PY'
import pathlib, sys
p = pathlib.Path(sys.argv[1])
s = p.read_text()
needle = '    location = /v1/agent/ping {'
assert needle in s, 'Existing ping endpoint missing'
block = """    location = /v1/agent/workspace-report {
        auth_basic off;
        limit_except POST { deny all; }
        client_max_body_size 2k;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /opt/devmcp-admin/public/agent-workspace-report.php;
        fastcgi_param HTTPS on;
        fastcgi_param DEVMCP_DISTRIBUTED_CONFIG /etc/devmcp-admin/distributed.php;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
"""
s = s.replace(needle, block + needle, 1)
p.write_text(s)
PY
fi
nginx -t
systemctl reload nginx
echo 'Server workspace reporting enabled.'
