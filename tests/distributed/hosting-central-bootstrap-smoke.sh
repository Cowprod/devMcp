#!/usr/bin/env bash
# Contract-only central bootstrap checks. Never requires root or calls systemd.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd -P)"
installer="$root/deploy/distributed/bootstrap-hosting-central.sh"
operator="$root/deploy/distributed/install-hosting-central-operator.sh"

bash -n "$installer"
# Missing explicit consent must fail before *any* root-level action.
if bash "$installer" >/dev/null 2>&1; then
  echo 'Central bootstrap allowed implicit root approval' >&2
  exit 1
fi
if bash "$installer" --automatic >/dev/null 2>&1; then
  echo 'Central bootstrap accepted an unrelated approval' >&2
  exit 1
fi

# All of the privileged dependencies must be trusted before the first write.
for required in \
  '[[ "${BASH_SOURCE[0]}" == "$prefix/bootstrap-hosting-central.sh" ]]' \
  'trusted_dir "$dir"' \
  'trusted_file "$file"' \
  'HostingLabCentralInspection.php' \
  'remote_hosting_queue_enabled' \
  'hosting_lab_operator_socket_enabled' \
  'hosting_lab_operator_machine_name' \
  'getent group devmcp' \
  'Conflicting central hosting operator systemd unit' \
  'no jobs queued and no applications deployed'; do
  grep -Fq "$required" "$installer" || {
    echo "Missing central bootstrap trust contract: $required" >&2
    exit 1
  }
done
grep -Fq '/opt/devmcp/src/Distributed/Admin/HostingLabCentralInspection.php' "$operator" || {
  echo 'Socket installer can activate without its inspection dependency' >&2
  exit 1
}

# The first mutation is allowed only *after* both separate root opt-ins,
# immutable dependencies and conflicting unit checks have succeeded.
python3 - "$installer" <<'PY'
import pathlib, sys
source = pathlib.Path(sys.argv[1]).read_text()
marks = [
    'trusted_file "$file" || {',
    'hosting_lab_operator_socket_enabled',
    'Conflicting central hosting operator systemd unit',
    '/usr/bin/bash "$prefix/enable-hosting-central-routes.sh" --root-approved',
    '/usr/bin/bash "$prefix/install-hosting-central-operator.sh" --root-approved',
]
positions = [source.index(mark) for mark in marks]
if positions != sorted(positions) or len(set(positions)) != len(positions):
    raise SystemExit('Central bootstrap changed the trust/consent/install sequence')
if 'sudo ' in source or 'systemctl' in source or 'nginx -s' in source:
    raise SystemExit('Wrapper must delegate all privileged mutations to approved installers')
PY
echo 'PASS: one-command TouchDeck bootstrap enforces separate root approvals and installer order'
