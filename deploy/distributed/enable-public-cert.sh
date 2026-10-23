#!/usr/bin/env bash
# Narrow privileged operation: publish ONLY the public devMcp admin certificate.
set -euo pipefail
[[ $# -eq 0 && "${EUID}" -eq 0 ]] || { echo "Root and no arguments required" >&2; exit 2; }
CONF=/etc/nginx/sites-available/devmcp-admin.conf
CERT=/etc/devmcp-admin/tls/admin.crt
[[ -f "$CONF" && ! -L "$CONF" && -f "$CERT" && ! -L "$CERT" ]] || exit 2
/usr/bin/openssl x509 -in "$CERT" -noout >/dev/null
/usr/bin/python3 - "$CONF" <<'PY'
import os, pathlib, sys, tempfile
p = pathlib.Path(sys.argv[1])
s = p.read_text()
location = """    location = /devmcp-ca.crt {
        auth_basic off;
        limit_except GET { deny all; }
        default_type application/x-pem-file;
        alias /etc/devmcp-admin/tls/admin.crt;
        add_header Cache-Control "no-store" always;
    }
"""
if 'location = /devmcp-ca.crt' in s:
    if location not in s:
        raise SystemExit("Existing certificate location differs; refusing")
    print("Certificate location already configured")
    sys.exit(0)
marker = "    location / {\n        return 404;\n    }"
if s.count(marker) != 1:
    raise SystemExit("Unexpected vhost layout; refusing")
updated = s.replace(marker, location + marker)
fd, temp = tempfile.mkstemp(prefix=".devmcp-admin-", dir=str(p.parent))
try:
    with os.fdopen(fd, "w") as f:
        f.write(updated)
    os.chmod(temp, 0o644)
    os.replace(temp, str(p))
finally:
    if os.path.exists(temp):
        os.unlink(temp)
PY
/usr/sbin/nginx -t
/bin/systemctl reload nginx
echo "Public certificate endpoint configured."
