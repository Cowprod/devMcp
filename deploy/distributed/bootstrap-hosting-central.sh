#!/usr/bin/env bash
# Explicit CENTRAL TouchDeck bootstrap for the hosting queue HTTPS routes and
# three-demo operator socket. No implicit consent, project action grant or
# changes to caseRouter, router networking, or application services.
set -euo pipefail
umask 077

if [[ "$(id -u)" != 0 || "$#" != 1 || "$1" != '--root-approved' ]]; then
  echo 'Usage (TouchDeck root only): /opt/devmcp/deploy/distributed/bootstrap-hosting-central.sh --root-approved' >&2
  exit 2
fi

prefix=/opt/devmcp/deploy/distributed
config=/etc/devmcp-admin/distributed.php
# An otherwise trusted checkout does not make a copied /tmp bootstrap trusted.
[[ "${BASH_SOURCE[0]}" == "$prefix/bootstrap-hosting-central.sh" ]] || {
  echo 'Untrusted central bootstrap location' >&2
  exit 1
}
trusted_dir() {
  local owner mode
  [[ -d "$1" && ! -L "$1" ]] || return 1
  read -r owner mode < <(stat -c '%u %a' "$1")
  [[ "$owner" == 0 ]] && (( (8#$mode & 0022) == 0 ))
}
trusted_file() {
  local owner mode links size
  [[ -f "$1" && ! -L "$1" ]] || return 1
  read -r owner mode links size < <(stat -c '%u %a %h %s' "$1")
  [[ "$owner" == 0 && "$links" == 1 && "$size" -gt 0 && "$size" -le 65536 ]] &&
    (( (8#$mode & 0022) == 0 ))
}
# Check the COMPLETE path of every root-executed PHP/Bash dependency BEFORE
# making the first Nginx change; a writable ancestor permits atomic swaps.
for dir in /opt /opt/devmcp /opt/devmcp/bin /opt/devmcp/deploy "$prefix" \
  /opt/devmcp/src /opt/devmcp/src/Distributed \
  /opt/devmcp/src/Distributed/Admin /opt/devmcp/src/Distributed/Registry \
  /etc /etc/devmcp-admin /etc/systemd /etc/systemd/system; do
  trusted_dir "$dir" || {
    echo 'Untrusted central bootstrap source or configuration directory' >&2
    exit 1
  }
done
for file in "$prefix/bootstrap-hosting-central.sh" \
  "$prefix/enable-hosting-central-routes.sh" \
  "$prefix/install-hosting-central-operator.sh" \
  "$prefix/render-hosting-central-routes.php" \
  "$prefix/nginx-hosting-routes.conf" \
  "$prefix/devmcp-hosting-central-operator.socket" \
  "$prefix/devmcp-hosting-central-operator@.service" \
  /opt/devmcp/bin/devmcp-hosting-central-operator \
  /opt/devmcp/bin/devmcp-hosting-central-submit \
  /opt/devmcp/src/Distributed/Admin/HostingLabBatchConsent.php \
  /opt/devmcp/src/Distributed/Admin/HostingLabOperatorRequest.php \
  /opt/devmcp/src/Distributed/Admin/HostingLabSchemaMigration.php \
  /opt/devmcp/src/Distributed/Admin/HostingLabMachineResolver.php \
  /opt/devmcp/src/Distributed/Admin/HostingLabBatchEnqueue.php \
  /opt/devmcp/src/Distributed/Admin/HostingLabCentralInspection.php \
  /opt/devmcp/src/Distributed/Registry/DatabaseConnection.php \
  "$config"; do
  trusted_file "$file" || {
    echo 'Untrusted or missing central bootstrap dependency' >&2
    exit 1
  }
done
[[ -x /usr/bin/php && -x /usr/bin/bash ]] || {
  echo 'Required central PHP or Bash interpreter missing' >&2
  exit 1
}
getent group devmcp >/dev/null || {
  echo 'Central devmcp socket group missing' >&2
  exit 1
}
# Two INDEPENDENT root approvals. The wrapper MUST NOT write them. An absent
# socket grant cannot leave the HTTPS routes partially installed by this run.
/usr/bin/php -r '
$c = require $argv[1];
exit(is_array($c)
  && ($c["remote_hosting_queue_enabled"] ?? null) === true
  && ($c["hosting_lab_operator_socket_enabled"] ?? null) === true
  && ($c["hosting_lab_operator_machine_name"] ?? null) === "caseRouter"
  && !empty($c["writer_username"]) && !empty($c["writer_password"]) ? 0 : 1);
' "$config" || {
  echo 'Both explicit central hosting approvals are required before bootstrap' >&2
  exit 1
}
# Reject foreign units before changing Nginx. Each underlying installer
# subsequently revalidates its own preconditions and holds its own lock.
for name in devmcp-hosting-central-operator.socket devmcp-hosting-central-operator@.service; do
  target="/etc/systemd/system/$name"
  if [[ -e "$target" || -L "$target" ]]; then
    [[ -f "$target" && ! -L "$target" ]] &&
      trusted_file "$target" &&
      [[ "$(stat -c '%u:%g:%a:%h' "$target")" == '0:0:644:1' ]] &&
      cmp -s "$prefix/$name" "$target" || {
      echo 'Conflicting central hosting operator systemd unit' >&2
      exit 1
    }
  fi
done

# The two operations each have their own atomic replacement and rollback.
# If the second fails, the independently authorized HTTPS routes may remain;
# the operator socket is NOT reported installed in that case.
/usr/bin/bash "$prefix/enable-hosting-central-routes.sh" --root-approved
/usr/bin/bash "$prefix/install-hosting-central-operator.sh" --root-approved
echo 'PASS: central HTTPS routes and root operator socket installed; no jobs queued and no applications deployed'
