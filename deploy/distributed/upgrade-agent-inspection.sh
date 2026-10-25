#!/usr/bin/env bash
# Upgrade existing enrolled agent only; no changes to identity, policy or jobs.
set -euo pipefail
[[ "$(id -u)" -eq 0 && "$#" -eq 1 && "$1" =~ ^[a-f0-9]{40}$ ]] || { echo "Usage: sudo bash upgrade-agent-inspection.sh COMMIT_SHA" >&2; exit 2; }
[[ -f /etc/devmcp/agent.php && -f /opt/devmcp-agent/bin/distributed-agent.php ]] || { echo "Agent not enrolled" >&2; exit 1; }
base="https://raw.githubusercontent.com/Cowprod/devMcp/$1"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
for file in distributed-agent.php inspect-workspace.php; do
  curl --proto '=https' --tlsv1.2 --fail --silent --show-error --location --max-time 30 "$base/bin/$file" -o "$tmp/$file"
  php -l "$tmp/$file"
done
install -m 0755 -o root -g root "$tmp/inspect-workspace.php" /opt/devmcp-agent/bin/inspect-workspace.php
install -m 0755 -o root -g root "$tmp/distributed-agent.php" /opt/devmcp-agent/bin/distributed-agent.php
systemctl start devmcp-agent.service
journalctl -u devmcp-agent.service -n 8 --no-pager
