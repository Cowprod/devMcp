#!/usr/bin/env bash
# Upgrade the enrolled agent binaries without enabling remote execution.
set -euo pipefail
[[ "$(id -u)" -eq 0 && "$#" -eq 1 && "$1" =~ ^[a-f0-9]{40}$ ]] || { echo "Usage: sudo bash upgrade-agent-readonly.sh COMMIT_SHA" >&2; exit 2; }
[[ -f /etc/devmcp/agent.php ]] || { echo "Agent not enrolled" >&2; exit 1; }
base="https://raw.githubusercontent.com/Cowprod/devMcp/$1/bin"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT
for file in distributed-agent.php inspect-workspace.php remote-readonly-actions.php remote-job-policy.php remote-local-policy.php remote-job-dispatch.php remote-workspace-write-text.php; do
    curl --proto '=https' --tlsv1.2 --fail --silent --show-error --location --max-time 30 "$base/$file" -o "$tmp/$file"
    php -l "$tmp/$file"
done
for file in distributed-agent.php inspect-workspace.php remote-readonly-actions.php remote-job-policy.php remote-local-policy.php remote-job-dispatch.php remote-workspace-write-text.php; do
    install -m 0755 -o root -g root "$tmp/$file" "/opt/devmcp-agent/bin/$file"
done
systemctl start devmcp-agent.service
echo "PASS: read-only agent upgraded; execution still requires local policy"
