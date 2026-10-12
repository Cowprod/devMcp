#!/bin/sh
set -eu
[ "$(id -u)" -eq 0 ] || { echo "Run as root" >&2; exit 1; }
install -m 0440 /opt/devmcp/deploy/sudoers/devmcp-update /etc/sudoers.d/devmcp-update
visudo -cf /etc/sudoers.d/devmcp-update
# One-time registration in existing instance; refuse ambiguous configurations.
python3 - <<'PY'
from pathlib import Path
p=Path('/etc/devmcp/global.php')
s=p.read_text()
needle="'node.dependencies',"
entry="'devmcp.update',"
if entry not in s:
    if s.count(needle) != 1:
        raise SystemExit("Cannot locate unique project action list; no config changes")
    p.with_suffix('.php.before-update-trigger').write_text(s)
    p.write_text(s.replace(needle,needle+"\n                "+entry,1))
PY
php -l /etc/devmcp/global.php
