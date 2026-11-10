#!/usr/bin/env bash
# Explicit one-time CENTRAL root-only opt-in for a three-demo SQL enqueue socket.
# Installs only new namespaced systemd units on TouchDeck. No router, network,
# nginx, agent policy, application service or SQL row is changed by this script.
set -euo pipefail
umask 077
# Never resolve privileged utilities or PHP from an inherited executable path.
# Pre-start BASH_ENV evaluation still requires caller-side environment reset.
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
unset CDPATH ENV BASH_ENV PHPRC PHP_INI_SCAN_DIR LD_PRELOAD LD_LIBRARY_PATH LD_AUDIT

[[ "$(id -u)" == 0 && "$#" == 1 && "$1" == "--root-approved" ]] || {
  echo 'Usage (TouchDeck central root only): install-hosting-central-operator.sh --root-approved' >&2
  exit 2
}
# Prevent overlapping administrator runs from snapshotting stale socket states.
# A failed second installer must never stop/disable a successful first one.
# Reuse a persistent root-owned inode instead of truncating a live lock file.
lock_dir=/run/lock
lock_path="$lock_dir/devmcp-hosting-central-operator.lock"
[[ -d "$lock_dir" && ! -L "$lock_dir" &&
   "$(stat -c '%u:%g' "$lock_dir")" == '0:0' ]] || {
  echo 'Untrusted central operator lock directory' >&2
  exit 1
}
if [[ ! -e "$lock_path" && ! -L "$lock_path" ]]; then
  ( set -C; : > "$lock_path" ) 2>/dev/null || {
    echo 'Unable to create exclusive central operator lock' >&2
    exit 1
  }
fi
[[ -f "$lock_path" && ! -L "$lock_path" &&
   "$(stat -c '%u:%g:%a:%h' "$lock_path")" == '0:0:600:1' ]] || {
  echo 'Untrusted central operator lock file' >&2
  exit 1
}
command -v flock >/dev/null
exec 9<>"$lock_path"
flock -n 9 || {
  echo 'Another central operator installation is in progress' >&2
  exit 1
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
  /opt/devmcp/deploy /opt/devmcp/deploy/distributed \
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
  /opt/devmcp/src/Distributed/Admin/HostingLabCentralInspection.php \
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
socket_unit=devmcp-hosting-central-operator.socket
# Capture the independent runtime states BEFORE altering any unit files.
# A socket can be enabled yet inactive (or active yet disabled); rollback
# must preserve both bits rather than blindly disabling an existing service.
was_active=0
was_enabled=0
if systemctl is-active --quiet "$socket_unit"; then was_active=1; fi
if systemctl is-enabled --quiet "$socket_unit"; then was_enabled=1; fi

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

# The installation of TWO units and activation of the socket is one
# operator-approved change. On a failure (including a socket ACL mismatch),
# undo only files created by this invocation and restore the previous
# enabled/active states. Never remove an already installed matching unit.
installed_units=()
staged_units=()
mutation_started=0
installation_committed=0
rollback_operator_install() {
  rc=$?
  trap - EXIT
  if [[ "$rc" -ne 0 && "$mutation_started" -eq 1 &&
        "$installation_committed" -eq 0 ]]; then
    if [[ "$was_active" -eq 0 ]]; then
      systemctl stop "$socket_unit" >/dev/null 2>&1 ||
        echo 'WARNING: central operator socket stop failed during rollback' >&2
    fi
    if [[ "$was_enabled" -eq 0 ]]; then
      systemctl disable "$socket_unit" >/dev/null 2>&1 ||
        echo 'WARNING: central operator socket disable failed during rollback' >&2
    fi
    for stage in "${staged_units[@]}"; do
      rm -f -- "$stage" ||
        echo 'WARNING: central operator unit staging cleanup failed' >&2
    done
    for name in "${installed_units[@]}"; do
      target="/etc/systemd/system/$name"
      # Do not delete a file which an independent administrator has modified.
      if [[ -f "$target" && ! -L "$target" ]] &&
         trusted_file "$target" && cmp -s "$prefix/$name" "$target"; then
        rm -f -- "$target" ||
          echo "WARNING: central operator unit cleanup failed: $name" >&2
      else
        echo "WARNING: central operator unit changed during rollback: $name" >&2
      fi
    done
    if [[ "${#installed_units[@]}" -gt 0 ]]; then
      systemctl daemon-reload ||
        echo 'WARNING: central operator daemon-reload failed during rollback' >&2
    fi
    echo 'FAIL: central operator installation rejected; rollback attempted' >&2
  fi
  exit "$rc"
}
trap rollback_operator_install EXIT

for name in devmcp-hosting-central-operator.socket devmcp-hosting-central-operator@.service; do
  target="/etc/systemd/system/$name"
  if [[ ! -e "$target" ]]; then
    mutation_started=1
    # Stage in the same filesystem; never leave half-written systemd units.
    stage="$(mktemp "/etc/systemd/system/.$name.XXXXXXXX")"
    staged_units+=("$stage")
    install -m 0644 -o root -g root "$prefix/$name" "$stage"
    [[ ! -e "$target" && ! -L "$target" ]] || {
      echo 'Central operator systemd unit appeared during installation' >&2
      exit 1
    }
    mv -n -- "$stage" "$target"
    # Track a successful placement BEFORE validation so a failed post-move
    # permission/content check can still remove our newly created unit.
    # GNU mv -n returns success even when it skips an existing target:
    # in that case the source stage remains and must NOT be tracked.
    if [[ ! -e "$stage" && -f "$target" && ! -L "$target" ]]; then
      installed_units+=("$name")
    else
      echo 'Central operator systemd unit appeared during installation' >&2
      exit 1
    fi
    [[ "$(stat -c '%u:%g:%a:%h' "$target")" == '0:0:644:1' ]] &&
      cmp -s "$prefix/$name" "$target" || {
      echo 'Central operator systemd unit changed during installation' >&2
      exit 1
    }
  fi
done
mutation_started=1
systemctl daemon-reload
systemctl enable --now "$socket_unit"
systemctl is-active --quiet "$socket_unit"
socket="$runtime/operator.sock"
[[ -S "$socket" && ! -L "$socket" &&
   "$(stat -c '%u:%g:%a:%h' "$socket")" == "0:$group_gid:660:1" ]] || {
  echo 'Central operator socket ACL check rejected' >&2
  exit 1
}
installation_committed=1
echo 'PASS: root-approved central lab operator IPC installed; no job enqueued or application deployed'
