#!/bin/sh
# One-time privileged bootstrap, with automatic daily updates afterwards.
set -eu
[ "$(id -u)" -eq 0 ] || { echo "Run as root" >&2; exit 1; }
cp /opt/devmcp/deploy/systemd/devmcp-update.service /etc/systemd/system/devmcp-update.service
cp /opt/devmcp/deploy/systemd/devmcp-update.timer /etc/systemd/system/devmcp-update.timer
sh /opt/devmcp/deploy/install-update-trigger.sh
systemctl daemon-reload
systemctl enable --now devmcp-update.timer
systemctl start devmcp-update.service
systemctl status devmcp-update.timer --no-pager
