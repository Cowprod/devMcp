#!/usr/bin/env bash
# Administrator-only opt-in for the separate hosting IPC. Never called by
# agent self-update. No WAN/LAN, routes, NAT, firewall or router app changes.
set -euo pipefail
umask 077
[[ "$(id -u)" == 0 && "$#" == 1 && "$1" =~ ^[a-f0-9]{40}$ ]] || {
  echo 'Usage (root): install-hosting-gateway.sh IMMUTABLE_AGENT_SHA' >&2; exit 2;
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
[[ "$(git -C "$checkout" rev-parse HEAD)" == "$revision" ]] || {
  echo 'Installed bundle does not match the selected checkout SHA' >&2; exit 1;
}
for filename in devmcp-hosting.socket devmcp-hosting@.service; do
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
[[ ! -L "$root/hosting-active" || "$(readlink "$root/hosting-active")" == "hosting-bundles/$revision" ]] || {
  echo 'Conflicting existing hosting-active pointer' >&2; exit 1;
}
[[ ! -e "$root/hosting-active" || -L "$root/hosting-active" ]] || {
  echo 'Hosting active path occupied' >&2; exit 1;
}
if [[ ! -L "$root/hosting-active" ]]; then
  ln -s "hosting-bundles/$revision" "$root/.hosting-active-$revision"
  mv -T -- "$root/.hosting-active-$revision" "$root/hosting-active"
fi
for filename in devmcp-hosting.socket devmcp-hosting@.service; do
  destination="/etc/systemd/system/$filename"
  if [[ ! -e "$destination" ]]; then
    install -m 0644 -o root -g root "$checkout/deploy/distributed/$filename" "$destination"
  fi
done
# Only the dedicated new socket is enabled; no application unit is started.
systemctl daemon-reload
systemctl enable --now devmcp-hosting.socket
systemctl is-active --quiet devmcp-hosting.socket
echo "PASS: hosting IPC socket opted in at $revision; zero applications activated"
