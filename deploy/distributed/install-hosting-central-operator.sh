#!/usr/bin/env bash
# Explicit one-time CENTRAL root-only opt-in for a three-demo SQL enqueue socket.
# Installs only new namespaced systemd units on TouchDeck. No router, network,
# nginx, agent policy, application service or SQL row is changed by this script.
set -euo pipefail
umask 077

[[ "$(id -u)" == 0 && "$#" == 1 && "$1" == "--root-approved" ]] || {
  echo 'Usage (TouchDeck central root only): install-hosting-central-operator.sh --root-approved' >&2
  exit 2
}
trusted_dir() {
  [[ -d "$1" && ! -L "$1" && "$(stat -c '%u' "$1")" == 0 &&
     $((8#$(stat -c '%a' "$1") & 0022)) == 0 ]]
}
trusted_file() {
  [[ -f "$1" && ! -L "$1" && "$(stat -c '%u:%h' "$1")" == "0:1" &&
     $((8#$(stat -c '%a' "$1") & 0022)) == 0 ]]
}
for dir in /opt /opt/devmcp /opt/devmcp/bin /opt/devmcp/src \
  /opt/devmcp/src/Distributed /opt/devmcp/src/Distributed/Admin \
  /opt/devmcp/src/Distributed/Registry /etc /etc/devmcp-admin \
  /etc/systemd /etc/systemd/system; do
  trusted_dir "$dir" || { echo 'Untrusted central operator parent directory' >&2; exit 1; }
done
for file in /opt/devmcp/bin/devmcp-hosting-central-operator \
  /opt/devmcp/bin/devmcp-hosting-central-submit \
  /opt/devmcp/src/Distributed/Admin/HostingLabOperatorRequest.php \
  /opt/devmcp/src/Distributed/Admin/HostingLabBatchConsent.php \
  /opt/devmcp/src/Distributed/Admin/HostingLabBatchEnqueue.php \
  /opt/devmcp/src/Distributed/Admin/HostingLabSchemaMigration.php \
  /opt/devmcp/src/Distributed/Admin/HostingLabMachineResolver.php \
  /opt/devmcp/src/Distributed/Registry/DatabaseConnection.php \
  /etc/devmcp-admin/distributed.php; do
  trusted_file "$file" || { echo 'Untrusted central operator code or root configuration' >&2; exit 1; }
  [[ "$(stat -c %s "$file")" -gt 0 && "$(stat -c %s "$file")" -le 65536 ]] || exit 1
done

# Separate root consent: queue enrollment alone MUST NOT grant a socket.
php -r '
$c = require $argv[1];
exit(is_array($c) && ($c["remote_hosting_queue_enabled"] ?? null) === true
    && ($c["hosting_lab_operator_socket_enabled"] ?? null) === true
    && ($c["hosting_lab_operator_machine_name"] ?? null) === "caseRouter"
    && !empty($c["writer_username"]) && !empty($c["writer_password"]) ? 0 : 1);
' /etc/devmcp-admin/distributed.php || {
  echo 'Central root operator socket not explicitly opted in' >&2
  exit 1
}
for code in /opt/devmcp/bin/devmcp-hosting-central-operator \
  /opt/devmcp/bin/devmcp-hosting-central-submit \
  /opt/devmcp/src/Distributed/Admin/HostingLabOperatorRequest.php; do
  php -l "$code" >/dev/null
done
group_record="$(getent group devmcp)"
[[ -n "$group_record" ]] || { echo 'devmcp worker group missing' >&2; exit 1; }
group_gid="$(printf '%s' "$group_record" | cut -d: -f3)"
[[ "$group_gid" =~ ^[0-9]+$ ]] || exit 1

# Ensure the existing HTTPS ingestion path is at least configured before
# granting the central worker the ability to enqueue privileged lab jobs.
vhost=/etc/nginx/sites-available/devmcp-admin.conf
trusted_file "$vhost" || { echo 'Untrusted central HTTPS vhost' >&2; exit 1; }
grep -Fq 'location = /v1/agent/hosting-poll {' "$vhost" &&
grep -Fq 'location = /v1/agent/hosting-result {' "$vhost" || {
  echo 'Central HTTPS hosting routes must be installed first' >&2
  exit 1
}
# An anchor string in a comment or a partial location is not a valid route.
# The existing pure renderer must consider the live vhost fully installed
# and return byte-identical content; otherwise this socket stays disabled.
renderer=/opt/devmcp/deploy/distributed/render-hosting-central-routes.php
routes=/opt/devmcp/deploy/distributed/nginx-hosting-routes.conf
trusted_file "$renderer" && trusted_file "$routes" || {
  echo 'Untrusted central hosting route verifier' >&2; exit 1
}
php "$renderer" "$vhost" | cmp -s - "$vhost" || {
  echo 'Central hosting locations are not the exact approved template' >&2
  exit 1
}
nginx -t >/dev/null
runtime=/run/devmcp-hosting-central
if [[ -e "$runtime" || -L "$runtime" ]]; then
  [[ -d "$runtime" && ! -L "$runtime" &&
     "$(stat -c '%u:%g:%a' "$runtime")" == '0:0:755' ]] || {
    echo 'Untrusted central operator runtime directory' >&2
    exit 1
  }
fi

prefix=/opt/devmcp/deploy/distributed
for name in devmcp-hosting-central-operator.socket devmcp-hosting-central-operator@.service; do
  source="$prefix/$name"
  target="/etc/systemd/system/$name"
  trusted_file "$source" || { echo 'Untrusted central operator systemd template' >&2; exit 1; }
  if [[ -e "$target" || -L "$target" ]]; then
    [[ -f "$target" && ! -L "$target" &&
       "$(stat -c '%u:%g:%a:%h' "$target")" == '0:0:644:1' ]] &&
      cmp -s "$source" "$target" || {
      echo 'Conflicting central operator systemd unit' >&2
      exit 1
    }
  fi
done
for name in devmcp-hosting-central-operator.socket devmcp-hosting-central-operator@.service; do
  target="/etc/systemd/system/$name"
  [[ -e "$target" ]] ||
    install -m 0644 -o root -g root "$prefix/$name" "$target"
done
systemctl daemon-reload
systemctl enable --now devmcp-hosting-central-operator.socket
systemctl is-active --quiet devmcp-hosting-central-operator.socket
socket="$runtime/operator.sock"
[[ -S "$socket" && ! -L "$socket" &&
   "$(stat -c '%u:%g:%a:%h' "$socket")" == "0:$group_gid:660:1" ]] || {
  echo 'Central operator socket ACL check rejected' >&2
  exit 1
}
echo 'PASS: root-approved central lab operator IPC installed; no job enqueued or application deployed'
