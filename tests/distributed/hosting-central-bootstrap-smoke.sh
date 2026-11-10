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
# A hostile PATH must not trigger a caller-supplied binary prior to root consent.
spoof_dir="$(mktemp -d)"
trap 'rm -rf -- "$spoof_dir"' EXIT
cat > "$spoof_dir/id" <<'SH'
#!/bin/sh
: > "$DEVMCP_SPOOF_MARKER"
exit 0
SH
chmod 0755 "$spoof_dir/id"
for script in "$installer" "$operator" "$root/deploy/distributed/enable-hosting-central-routes.sh"; do
  marker="$spoof_dir/$(basename "$script").seen"
  if DEVMCP_SPOOF_MARKER="$marker" PATH="$spoof_dir:/usr/sbin:/usr/bin:/sbin:/bin" \
       /usr/bin/bash "$script" --not-approved >/dev/null 2>&1; then
    echo 'Root hosting installer accepted an unapproved invocation' >&2
    exit 1
  fi
  if [[ -e "$marker" ]]; then
    echo "Root hosting installer executed a caller-supplied PATH binary: $script" >&2
    exit 1
  fi
done
python3 - "$installer" "$operator" "$root/deploy/distributed/enable-hosting-central-routes.sh" <<'PY'
import pathlib, sys
for path in sys.argv[1:]:
    source = pathlib.Path(path).read_text()
    path_reset = "export PATH=/usr/sbin:/usr/bin:/sbin:/bin"
    env_reset = "unset CDPATH ENV BASH_ENV PHPRC PHP_INI_SCAN_DIR LD_PRELOAD LD_LIBRARY_PATH LD_AUDIT"
    if path_reset not in source or env_reset not in source:
        raise SystemExit("Missing root installer environment hygiene: " + path)
    if source.index(path_reset) > source.index("id -u") or source.index(env_reset) > source.index("id -u"):
        raise SystemExit("Root environment sanitation occurs after a subprocess: " + path)
PY
echo 'PASS: one-command TouchDeck bootstrap enforces separate root approvals and installer order'
