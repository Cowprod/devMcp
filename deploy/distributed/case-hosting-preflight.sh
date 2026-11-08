#!/usr/bin/env bash
# Read-only preflight for the initial caseRouter hosting bootstrap.
set -euo pipefail
if [[ ${EUID} -ne 0 ]]; then
  echo "ERROR: run as root (sudo)." >&2
  exit 1
fi
printf 'host=%s\n' "$(hostname)"
printf 'agent_config='
if [[ -f /etc/devmcp/agent.php ]]; then echo present; else echo missing; fi
printf 'hosting_policy='
if [[ -f /etc/devmcp/hosting-policy.json ]]; then echo present; else echo missing; fi
printf 'zerotier_service='
systemctl is-active zerotier-one.service || true
printf 'agent_services=\n'
systemctl list-units --type=service --all --no-pager --plain 'devmcp*' || true
printf 'runtime_versions=\n'
for binary in php node python3 git; do
  if command -v "$binary" >/dev/null 2>&1; then
    printf '%s: %s\n' "$binary" "$(command -v "$binary")"
  else
    printf '%s: missing\n' "$binary"
  fi
done
echo 'PREFLIGHT COMPLETE: no files, services or network settings changed.'
