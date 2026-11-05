#!/usr/bin/env bash
# One-time opt-in installation on an already enrolled Ubuntu/Debian agent.
# The argument is an immutable, operator-reviewed Cowprod/devMcp Git SHA.
set -euo pipefail
umask 077
[[ "$(id -u)" -eq 0 && "$#" -eq 1 && "$1" =~ ^[0-9a-f]{40}$ ]] || {
  echo 'Usage: sudo bash install-agent-self-update.sh EXACT_GIT_SHA' >&2
  exit 2
}
revision="$1"
[[ -f /etc/devmcp/agent.php && ! -L /etc/devmcp/agent.php
   && -f /opt/devmcp-agent/bin/distributed-agent.php ]] || {
  echo 'Agent enrollment and binaries must exist already' >&2
  exit 1
}
[[ -d /opt/devmcp-agent/bin && ! -L /opt/devmcp-agent/bin
   && -d /var/lib/devmcp-agent && ! -L /var/lib/devmcp-agent ]] || {
  echo 'Invalid agent installation paths' >&2
  exit 1
}
for name in curl php git systemctl bash; do
  command -v "$name" >/dev/null || { echo "Missing prerequisite: $name" >&2; exit 1; }
done
base="https://raw.githubusercontent.com/Cowprod/devMcp/$revision/deploy/distributed"
stage="$(mktemp -d)"
trap 'rm -rf -- "$stage"' EXIT
for file in self-update-agent.sh devmcp-agent-update.service devmcp-agent-update.timer; do
  curl --proto '=https' --tlsv1.2 --fail --silent --show-error \
    --max-time 25 --output "$stage/$file" "$base/$file"
  [[ -s "$stage/$file" ]] || { echo "Empty artifact: $file" >&2; exit 1; }
done
bash -n "$stage/self-update-agent.sh"
grep -Fqx 'ExecStart=/bin/bash /opt/devmcp-agent/bin/self-update-agent.sh' "$stage/devmcp-agent-update.service"
grep -Fqx 'Unit=devmcp-agent-update.service' "$stage/devmcp-agent-update.timer"
[[ ! -L /opt/devmcp-agent/bin/self-update-agent.sh
   && ! -L /etc/systemd/system/devmcp-agent-update.service
   && ! -L /etc/systemd/system/devmcp-agent-update.timer ]] || {
  echo 'Refusing to overwrite symbolic links' >&2; exit 1;
}
# Separate trusted release state from /var/lib/devmcp-agent, which is writable
# by the unprivileged agent account. The root updater must not consume a
# Git checkout controlled by that account.
[[ ! -L /var/lib/devmcp-agent-update ]] || { echo 'Linked update state rejected' >&2; exit 1; }
install -d -m 0700 -o root -g root /var/lib/devmcp-agent-update
[[ "$(stat -c %u /var/lib/devmcp-agent-update)" == 0 ]] || {
  echo 'Update state must be root-owned' >&2; exit 1;
}
install -m 0750 -o root -g root "$stage/self-update-agent.sh" /opt/devmcp-agent/bin/self-update-agent.sh
install -m 0644 -o root -g root "$stage/devmcp-agent-update.service" /etc/systemd/system/devmcp-agent-update.service
install -m 0644 -o root -g root "$stage/devmcp-agent-update.timer" /etc/systemd/system/devmcp-agent-update.timer
systemctl daemon-reload
systemctl enable --now devmcp-agent-update.timer
systemctl start devmcp-agent-update.service
systemctl is-enabled --quiet devmcp-agent-update.timer
echo 'PASS: agent self-update enabled; credentials and local action policy unchanged'
