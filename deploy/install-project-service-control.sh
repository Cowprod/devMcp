#!/bin/sh
# Root-only installer. Adds a fixed service template and an isolated request
# timer; it NEVER enables or starts an application project by itself.
set -eu
[ "$(id -u)" -eq 0 ] || { echo "root required" >&2; exit 1; }
base=/opt/devmcp/deploy/systemd
install -d -m 0700 -o devmcp -g devmcp /var/lib/devmcp/project-service-requests
install -d -m 2750 -o root -g devmcp /run/devmcp-project-results
install -m 0644 "$base/devmcp-project@.service" /etc/systemd/system/devmcp-project@.service
install -m 0644 "$base/devmcp-project-control.service" /etc/systemd/system/devmcp-project-control.service
install -m 0644 "$base/devmcp-project-control.timer" /etc/systemd/system/devmcp-project-control.timer
/usr/bin/php -l /opt/devmcp/bin/devmcp-project-runtime >/dev/null
/usr/bin/php -l /opt/devmcp/bin/devmcp-project-service >/dev/null
/usr/bin/php -l /opt/devmcp/bin/devmcp-project-service-control >/dev/null
systemctl daemon-reload
systemctl enable --now devmcp-project-control.timer
echo "PROJECT_SERVICE_CONTROLLER_READY (no application started)"
