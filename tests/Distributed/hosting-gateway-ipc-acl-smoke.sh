#!/usr/bin/env bash
# Exercise the production IPC ACL predicate without root, systemd or router access.
set -euo pipefail
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
installer=deploy/distributed/install-hosting-gateway.sh
functions="$(sed -n '/^hosting_socket_acl_ready() {/,/^}/p' "$installer")"
[[ "$functions" == *"hosting_socket_acl_ready()"* ]]
eval "$functions"

mkdir -p "$tmp/run"
chmod 0755 "$tmp/run"
socket="$tmp/run/agent.sock"
python3 - "$socket" <<'PY'
import socket
import sys
with socket.socket(socket.AF_UNIX, socket.SOCK_STREAM) as server:
    server.bind(sys.argv[1])
PY
chmod 0660 "$socket"
uid="$(id -u)"
gid="$(id -g)"
hosting_socket_acl_ready "$tmp/run" "$socket" "$uid" "$gid"

reject() {
  if hosting_socket_acl_ready "$tmp/run" "$socket" "$1" "$2"; then
    echo 'FAIL: hosting IPC ACL accepted an untrusted inode' >&2
    exit 1
  fi
}
reject "$((uid + 1))" "$gid"
reject "$uid" "$((gid + 1))"
chmod 0666 "$socket"
reject "$uid" "$gid"
chmod 0660 "$socket"
chmod 0777 "$tmp/run"
reject "$uid" "$gid"
chmod 0755 "$tmp/run"

mv "$socket" "$tmp/real-socket"
ln -s "$tmp/real-socket" "$socket"
reject "$uid" "$gid"
rm "$socket"
printf 'not a socket' > "$socket"
chmod 0660 "$socket"
reject "$uid" "$gid"
rm "$socket"
mv "$tmp/real-socket" "$socket"

mv "$tmp/run" "$tmp/real-run"
ln -s "$tmp/real-run" "$tmp/run"
reject "$uid" "$gid"
rm "$tmp/run"
mv "$tmp/real-run" "$tmp/run"
hosting_socket_acl_ready "$tmp/run" "$socket" "$uid" "$gid"

# Ensure the actual installer checks the fixed production inode BEFORE both
# changing the active privileged bundle and enabling queue polling.
acl_line="$(grep -nF 'hosting_socket_acl_ready /run/devmcp-hosting /run/devmcp-hosting/agent.sock 0' "$installer" | cut -d: -f1)"
promote_line="$(grep -nF '/bin/bash "$active_helper" promote "$revision"' "$installer" | cut -d: -f1)"
grant_line="$(grep -nF 'agent_policy=/etc/devmcp/hosting-agent-policy.php' "$installer" | cut -d: -f1)"
[[ "$acl_line" =~ ^[0-9]+$ && "$promote_line" =~ ^[0-9]+$ && "$grant_line" =~ ^[0-9]+$
   && "$acl_line" -lt "$promote_line" && "$promote_line" -lt "$grant_line" ]]
echo 'PASS: hosting socket type, owner, group, mode and root directory gate polling opt-in'
