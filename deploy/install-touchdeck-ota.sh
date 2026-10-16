#!/bin/sh
# Opt in only the existing TouchDeck project; never modify global permissions.
set -eu
[ "$(id -u)" -eq 0 ] || { echo "Root required" >&2; exit 1; }
python3 - <<'PY'
from pathlib import Path
p = Path('/etc/devmcp/global.php')
s = p.read_text()
entries = ["'firmware.status',", "'firmware.publish',", "'firmware.update',"]
if all(x in s for x in entries):
    print("TouchDeck OTA actions already registered")
else:
    # Existing single-project instance: require an unambiguous known action anchor.
    anchor = "'devmcp.update',"
    if s.count(anchor) != 1 or "'touchdeck'" not in s:
        raise SystemExit("Ambiguous TouchDeck project config; refusing changes")
    p.with_suffix('.php.before-touchdeck-ota').write_text(s)
    s = s.replace(anchor, anchor + "\n                " + "\n                ".join(entries), 1)
    p.write_text(s)
PY
/usr/bin/php -l /etc/devmcp/global.php
