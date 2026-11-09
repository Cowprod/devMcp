#!/usr/bin/env bash
# Administrator-only opt-in for the separate hosting IPC. Never called by
# agent self-update. No WAN/LAN, routes, NAT, firewall or router app changes.
set -euo pipefail
umask 077
# Require deliberate operator approval even when a LEGACY updater calls
# this script after checking out a newer revision. The updater passes only
# the SHA and must never be able to promote privileged code implicitly.
[[ "$(id -u)" == 0 && "$#" == 2 && "$1" =~ ^[a-f0-9]{40}$ && "$2" == "--root-approved" ]] || {
  echo 'Usage (local root only): install-hosting-gateway.sh IMMUTABLE_AGENT_SHA --root-approved' >&2; exit 2;
}
revision="$1"
root=/opt/devmcp-agent/bin
bundle="$root/hosting-bundles/$revision"
checkout=/var/lib/devmcp-agent-update/repository
policy=/etc/devmcp/hosting-policy.json
identity=/etc/devmcp/agent.php

trusted_file() {
  [[ -f "$1" && ! -L "$1" && "$(stat -c %u "$1")" == 0
     && "$(stat -c %h "$1")" == 1 && "$(stat -c %a "$1")" == "$2" ]]
}
trusted_dir() {
  [[ -d "$1" && ! -L "$1" && "$(stat -c %u "$1")" == 0
     && "$(stat -c %a "$1")" == "$2" ]]
}
# Render the exact approved opt-in as executable PHP (not escaped text).
hosting_agent_policy_payload() {
  printf "<?php\nreturn ['enabled' => true, 'machine_id' => '%s'];\n" "$1"
}
# Recognize ONLY the exact malformed file created by the previous installer.
hosting_agent_policy_legacy_payload() {
  printf '<?php\\nreturn [\\x27enabled\\x27 => true, \\x27machine_id\\x27 => \\x27%s\\x27];\\n' "$1"
}

# The agent's polling grant must never be published just because systemd
# reports an active unit. The socket must actually be a trustworthy Unix
# inode inside the root-controlled RuntimeDirectory. Parameters are fixed
# by the installer; allowing a different directory here is only for
# isolated, unprivileged regression testing of this predicate.
hosting_socket_acl_ready() {
  local parent="$1" socket="$2" expected_uid="$3" expected_gid="$4"
  local parent_stat socket_stat
  [[ -d "$parent" && ! -L "$parent" && -S "$socket" && ! -L "$socket" ]] || return 1
  parent_stat="$(stat -c '%u:%a' -- "$parent")" || return 1
  socket_stat="$(stat -c '%u:%g:%a:%h' -- "$socket")" || return 1
  [[ "$parent_stat" == "$expected_uid:755"
     && "$socket_stat" == "$expected_uid:$expected_gid:660:1" ]]
}

trusted_dir "$root" 755 && trusted_dir "$root/hosting-bundles" 755 &&
trusted_dir "$bundle" 755 && trusted_dir "$bundle/Project" 755 || {
  echo 'Missing or untrusted immutable hosting bundle' >&2; exit 1;
}
trusted_file "$bundle/devmcp-hosting-root" 755 &&
trusted_file "$bundle/devmcp-hosting-gateway" 755 &&
trusted_file "$policy" 600 || {
  echo 'Missing root-owned gateway or opt-in hosting policy (0600)' >&2; exit 1;
}
[[ -f "$identity" && ! -L "$identity" && "$(stat -c %u "$identity")" == 0 ]] || {
  echo 'Missing enrolled agent identity' >&2; exit 1;
}
[[ -d "$checkout/.git" && ! -L "$checkout" ]] || {
  echo 'Missing auto-update checkout' >&2; exit 1;
}
[[ -z "$(git -C "$checkout" status --porcelain --untracked-files=no)" ]] || {
  echo "Hosting source checkout is modified" >&2; exit 1;
}
[[ "$(git -C "$checkout" config --get remote.origin.url)" == "https://github.com/Cowprod/devMcp.git" ]] || {
  echo "Untrusted hosting source repository" >&2; exit 1;
}
[[ "$(git -C "$checkout" rev-parse HEAD)" == "$revision" ]] || {
  echo 'Installed bundle does not match the selected checkout SHA' >&2; exit 1;
}
for filename in devmcp-hosting.socket devmcp-hosting@.service devmcp-hosting-watchdog.service devmcp-hosting-watchdog.timer; do
  source_path="$checkout/deploy/distributed/$filename"
  [[ -f "$source_path" && ! -L "$source_path" ]] || {
    echo "Missing immutable unit: $filename" >&2; exit 1;
  }
  destination="/etc/systemd/system/$filename"
  if [[ -e "$destination" || -L "$destination" ]]; then
    trusted_file "$destination" 644 && cmp -s "$source_path" "$destination" || {
      echo "Conflicting systemd unit: $filename" >&2; exit 1;
    }
  fi
done
# Recheck the operator-owned local policy against the actual agent identity.
# This is an opt-in check, not an install-time grant manufactured by a job.
php -r '
$p = json_decode(file_get_contents($argv[1]), true, 16, JSON_THROW_ON_ERROR);
$c = require $argv[2];
if (!is_array($p) || !is_array($c) || ($p["version"] ?? null) !== 1 ||
    ($p["enabled"] ?? null) !== true ||
    ($p["machine_id"] ?? null) !== ($c["machine_id"] ?? null) ||
    !is_array($p["deployments"] ?? null) || count($p["deployments"]) < 1) {
    exit(1);
}
' "$policy" "$identity" || {
  echo 'Hosting policy not explicitly enabled for enrolled machine' >&2; exit 1;
}
php -l "$bundle/devmcp-hosting-root" >/dev/null
php -l "$bundle/devmcp-hosting-gateway" >/dev/null
# Local-root upgrade path: previous immutable pins are accepted only if
# they still point to a trusted versioned bundle. Never rotate from the agent
# updater, and never change a live gateway before all systemd checks succeed.
active_helper="$checkout/deploy/distributed/promote-hosting-active.sh"
[[ -f "$active_helper" && ! -L "$active_helper" ]] || {
  echo 'Missing fixed local hosting gateway promotion helper' >&2; exit 1;
}
bash -n "$active_helper"
/bin/bash "$active_helper" check "$revision"
for filename in devmcp-hosting.socket devmcp-hosting@.service devmcp-hosting-watchdog.service devmcp-hosting-watchdog.timer; do
  destination="/etc/systemd/system/$filename"
  if [[ ! -e "$destination" ]]; then
    install -m 0644 -o root -g root "$checkout/deploy/distributed/$filename" "$destination"
  fi
done
# Only the dedicated new socket is enabled; no application unit is started.
systemctl daemon-reload
systemctl enable --now devmcp-hosting.socket
systemctl is-active --quiet devmcp-hosting.socket
# Enabling a timer does not start an application. It only stops an
# abandoned first-release lab service after a durable 15-minute timeout.
systemctl enable --now devmcp-hosting-watchdog.timer
systemctl is-enabled --quiet devmcp-hosting-watchdog.timer
# Fail closed BEFORE either promoting the gateway or publishing a new
# agent polling grant. An active socket unit is not Unix ACL evidence.
trusted_dir /run 755 || { echo 'Untrusted hosting runtime parent' >&2; exit 1; }
hosting_group_record="$(getent group devmcp-agent)" || {
  echo 'Hosting agent group missing' >&2; exit 1;
}
hosting_group_gid="$(printf '%s' "$hosting_group_record" | cut -d: -f3)"
[[ "$hosting_group_gid" =~ ^[0-9]+$ ]] &&
  hosting_socket_acl_ready /run/devmcp-hosting /run/devmcp-hosting/agent.sock 0 "$hosting_group_gid" || {
  echo 'Privileged hosting IPC socket ownership or ACL rejected' >&2; exit 1;
}
# Atomic upgrade occurs LAST, only on explicit local-root opt-in.
# Subsequent socket activations resolve the new immutable gateway.
# Existing active requests retain their already-open executable inode.
/bin/bash "$active_helper" promote "$revision"
# The administrator has explicitly enabled hosting and installed its IPC.
# Complete the workflow by opting the enrolled agent into the hosting queue;
# otherwise the socket is live but no remote deployment can ever reach it.
agent_policy=/etc/devmcp/hosting-agent-policy.php
if [[ -e "$agent_policy" || -L "$agent_policy" ]]; then
  trusted_file "$agent_policy" 644 || { echo 'Conflicting agent hosting policy' >&2; exit 1; }
fi
machine_id="$(php -r '$c=require $argv[1]; echo $c["machine_id"] ?? "";' "$identity")"
[[ "$machine_id" =~ ^[a-f0-9]{32}$ ]] || { echo 'Invalid enrolled machine ID' >&2; exit 1; }
agent_tmp="$(mktemp /etc/devmcp/.hosting-agent-policy.XXXXXXXX)"
legacy_tmp="$(mktemp /etc/devmcp/.hosting-agent-legacy.XXXXXXXX)"
trap 'rm -f -- "$agent_tmp" "$legacy_tmp"' EXIT
hosting_agent_policy_payload "$machine_id" > "$agent_tmp"
hosting_agent_policy_legacy_payload "$machine_id" > "$legacy_tmp"
chmod 0644 "$agent_tmp"
# php -l cannot detect a malformed opening tag that PHP treats as plain text.
# Require that the included policy returns EXACT authorized values, silently.
php -r '
ob_start();
$p = require $argv[1];
$output = ob_get_clean();
exit($output === "" && is_array($p)
    && array_keys($p) === ["enabled", "machine_id"]
    && $p["enabled"] === true && $p["machine_id"] === $argv[2] ? 0 : 1);
' "$agent_tmp" "$machine_id" || {
  echo 'Generated hosting agent policy is invalid' >&2; exit 1;
}
if [[ -e "$agent_policy" ]] && ! cmp -s "$agent_tmp" "$agent_policy"; then
  if ! cmp -s "$legacy_tmp" "$agent_policy"; then
    echo 'Existing agent hosting policy differs; refusing overwrite' >&2
    exit 1
  fi
  echo 'PASS: repairing exact legacy malformed hosting agent opt-in'
fi
mv -f "$agent_tmp" "$agent_policy"
echo "PASS: hosting IPC, watchdog and remote hosting queue polling opted in at $revision; zero applications activated"
