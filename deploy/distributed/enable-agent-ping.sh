#!/usr/bin/env bash
# Narrow privileged operation: enable only the authenticated, non-executing
# pending-agent heartbeat endpoint. No claim/start/finish route is enabled.
set -euo pipefail
[[ $# -eq 0 && "${EUID}" -eq 0 ]] || { echo "Root and no arguments required" >&2; exit 2; }
CONF=/etc/nginx/sites-available/devmcp-admin.conf
TARGET=/opt/devmcp-admin/public/agent-ping.php
[[ -f "$CONF" && ! -L "$CONF" && -f "$TARGET" && ! -L "$TARGET" ]] || {
  echo "Missing Nginx config or deployed agent-ping.php" >&2; exit 2;
}
command -v php >/dev/null
php -l "$TARGET" >/dev/null
BACKUP="$(mktemp /etc/nginx/sites-available/.devmcp-admin-ping.XXXXXXXX)"
cp -p "$CONF" "$BACKUP"
restore() { cp -p "$BACKUP" "$CONF"; /usr/sbin/nginx -t >&2 || true; }
trap 'restore; rm -f "$BACKUP"' ERR
/usr/bin/python3 - "$CONF" <<'PY'
import os, pathlib, re, sys, tempfile
p = pathlib.Path(sys.argv[1])
s = p.read_text()
if 'location = /v1/agent/ping' in s:
    print('Agent ping already configured; no changes')
    sys.exit(0)
if re.search(r'location\s+~?\s*[^\n]*?/v1/agent/', s):
    raise SystemExit('Existing agent API routes require manual audit; refusing')
if 'zone=devmcp_agent:' in s:
    raise SystemExit('Agent rate limit already exists; refusing duplicate')
socket = re.search(r'fastcgi_pass\s+(unix:[^;]+);', s)
if socket is None:
    raise SystemExit('PHP-FPM socket not found; refusing')
marker = '    location / {\n        return 404;\n    }'
if s.count(marker) != 1:
    raise SystemExit('Unexpected Nginx layout; refusing')
zone = 'limit_req_zone $binary_remote_addr zone=devmcp_agent:10m rate=30r/m;\n'
if 'http {' in s:
    raise SystemExit('Expected a site file, not nginx.conf')
location = '''    location = /v1/agent/ping {
        auth_basic off;
        limit_except POST { deny all; }
        limit_req zone=devmcp_agent burst=10 nodelay;
        client_max_body_size 1k;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /opt/devmcp-admin/public/agent-ping.php;
        fastcgi_param HTTPS on;
        fastcgi_param DEVMCP_DISTRIBUTED_CONFIG /etc/devmcp-admin/distributed.php;
        fastcgi_pass SOCKET;
    }
'''.replace('SOCKET', socket.group(1))
# Site files cannot define limit_req_zone (http context). Require operator to
# provision the zone separately; never silently weaken the intended rate limit.
import subprocess
config_dump = subprocess.run(['/usr/sbin/nginx', '-T'], capture_output=True, text=True, check=True).stdout
if not re.search(r'^\\s*limit_req_zone\\s+\\$binary_remote_addr\\s+zone=devmcp_agent:10m\\s+rate=30r/m\\s*;', config_dump, re.MULTILINE):
    raise SystemExit('Missing devmcp_agent rate limit zone in effective Nginx configuration; refusing')
updated = s.replace(marker, location + marker)
fd, tmp = tempfile.mkstemp(prefix='.devmcp-ping-', dir=str(p.parent))
try:
    with os.fdopen(fd, 'w') as stream:
        stream.write(updated)
    os.chmod(tmp, 0o644)
    os.replace(tmp, str(p))
finally:
    if os.path.exists(tmp):
        os.unlink(tmp)
PY
/usr/sbin/nginx -t
/bin/systemctl reload nginx
trap - ERR
rm -f "$BACKUP"
echo "Authenticated pending-agent ping enabled. Remote job API remains disabled."
