#!/bin/sh
set -eu

if [ "$(id -u)" -ne 0 ]; then
    echo "Run this deployment helper as root." >&2
    exit 2
fi

DEVMCP_ROOT=${DEVMCP_ROOT:-/opt/devmcp}
CONFIG=${DEVMCP_CONFIG:-/etc/devmcp/global.php}

if [ ! -f "$CONFIG" ]; then
    echo "Missing devMcp config: $CONFIG" >&2
    exit 2
fi
if ! getent group dialout >/dev/null 2>&1; then
    echo "Missing system group: dialout" >&2
    exit 2
fi

/usr/bin/php -l "$CONFIG" >/dev/null

install -d -o devmcp -g devmcp -m 0700 /var/lib/devmcp /var/lib/devmcp/jobs /var/lib/devmcp/sessions
install -d -o devmcp -g devmcp -m 0750 /var/log/devmcp
install -d -o devmcp -g devmcp -m 0750 /srv/devmcp-workspaces

"$DEVMCP_ROOT/deploy/install-platformio.sh"

install -o root -g root -m 0644 "$DEVMCP_ROOT/deploy/systemd/devmcp-worker.service" /etc/systemd/system/devmcp-worker.service
install -o root -g root -m 0644 "$DEVMCP_ROOT/deploy/systemd/devmcp-tunnel.service" /etc/systemd/system/devmcp-tunnel.service

systemctl daemon-reload
systemctl enable devmcp-worker.service devmcp-tunnel.service >/dev/null
systemctl restart devmcp-worker.service
systemctl restart devmcp-tunnel.service

systemctl is-active --quiet devmcp-worker.service
systemctl is-active --quiet devmcp-tunnel.service

echo "devMcp runtime applied successfully."
/opt/devmcp-tools/platformio/bin/pio --version
