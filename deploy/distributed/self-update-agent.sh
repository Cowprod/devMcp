#!/usr/bin/env bash
# Approved agent binaries only. No edits to identities or local policies.
set -euo pipefail
umask 077
repo=https://github.com/Cowprod/devMcp.git
checkout=/var/lib/devmcp-agent-update/repository
root=/var/lib/devmcp-agent-update
bin=/opt/devmcp-agent/bin
marker=/var/lib/devmcp-agent-update/installed-revision
files="distributed-agent.php inspect-workspace.php agent-preview-port-probe.php remote-readonly-actions.php remote-job-policy.php remote-local-policy.php hosting-queue-policy.php remote-hosting-queue.php remote-project-queue.php"
updater_src="$checkout/deploy/distributed/self-update-agent.sh"
updater_dst="$bin/self-update-agent.sh"
[[ "$(id -u)" -eq 0 && "$#" -eq 0 ]] || { echo 'Requires root; no arguments' >&2; exit 2; }
[[ -f /etc/devmcp/agent.php && ! -L /etc/devmcp/agent.php ]] || { echo 'Agent not enrolled' >&2; exit 1; }
[[ -d "$root" && ! -L "$root" && -d "$bin" && ! -L "$bin" ]] || { echo 'Invalid agent directories' >&2; exit 1; }
# The unattended updater runs as root. Never truncate/follow an attacker-
# controlled lock path: /run/lock may be sticky and writable to other users.
# Keep one root-owned inode across runs, as for the hosting installers.
lock_path=/run/lock/devmcp-agent-update.lock
lock_dir="${lock_path%/*}"
[[ -d "$lock_dir" && ! -L "$lock_dir"
   && "$(stat -c '%u:%g' "$lock_dir")" == '0:0' ]] || {
  echo 'Untrusted agent updater lock directory' >&2
  exit 1
}
if [[ ! -e "$lock_path" && ! -L "$lock_path" ]]; then
  ( set -C; : > "$lock_path" ) 2>/dev/null || {
    echo 'Cannot create exclusive agent updater lock' >&2
    exit 1
  }
fi
[[ -f "$lock_path" && ! -L "$lock_path"
   && "$(stat -c '%u:%g:%a:%h' "$lock_path")" == '0:0:600:1' ]] || {
  echo 'Untrusted agent updater lock file' >&2
  exit 1
}
exec 9<>"$lock_path"
flock -n 9 || { echo 'Update already running'; exit 0; }

if [[ ! -d "$checkout/.git" ]]; then
  [[ ! -e "$checkout" ]] || { echo 'Source directory occupied' >&2; exit 1; }
  git -c core.hooksPath=/dev/null clone --quiet --single-branch --branch main -- "$repo" "$checkout"
fi
[[ ! -L "$checkout" && "$(git -C "$checkout" config --get remote.origin.url)" == "$repo" ]] || {
  echo 'Unexpected Git source' >&2; exit 1;
}
[[ -z "$(git -C "$checkout" status --porcelain --untracked-files=no)" ]] || { echo 'Dirty checkout' >&2; exit 1; }
git -C "$checkout" -c core.hooksPath=/dev/null fetch --quiet --no-tags origin main
target="$(git -C "$checkout" rev-parse refs/remotes/origin/main)"
[[ "$target" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid Git revision' >&2; exit 1; }
# A pinned SHA alone cannot prove that all files in a newly self-updated
# helper allowlist have been installed on the previous run.
[[ ! -L "$marker" ]] || { echo 'Linked revision marker rejected' >&2; exit 1; }
current=
if [[ -f "$marker" ]]; then
  current="$(cat "$marker")"
  [[ "$current" =~ ^[0-9a-f]{40}$ ]] || { echo 'Invalid installed revision' >&2; exit 1; }
  if [[ "$current" != "$target" ]]; then
    git -C "$checkout" merge-base --is-ancestor "$current" "$target" || {
      echo 'Non fast-forward update refused' >&2; exit 1;
    }
  fi
fi
git -C "$checkout" -c core.hooksPath=/dev/null checkout --quiet --detach "$target"

# Package the root-owned hosting primitives without installing units, adding
# remote actions or changing system services. A legacy updater first replaces
# itself; on its next timer tick this new updater completes the same-SHA bundle.
# The immutable bundle path is revision-specific, with strict drift refusal.
hosting_bundle="$checkout/deploy/distributed/stage-hosting-source-bundle.sh"
[[ -f "$hosting_bundle" && ! -L "$hosting_bundle" ]] || {
  echo 'Missing or linked fixed hosting bundle helper' >&2; exit 1;
}
bash -n "$hosting_bundle"
bash "$hosting_bundle" "$checkout" "$bin" "$target"

# Project-capable installations update their fixed broker with the same release.
# Existing hosting-enabled lab agents migrate to this complete project agent.
if [[ -f /etc/devmcp/agent-projects.php ]] || { [[ -f /etc/devmcp/hosting-policy.json ]] &&
  php -r '$p=json_decode(file_get_contents($argv[1]),true);exit(($p["enabled"]??false)===true?0:1);' /etc/devmcp/hosting-policy.json; }; then
  bash "$checkout/deploy/distributed/install-agent-projects.sh" "$checkout" "$target"
fi

# The updater may stage immutable hosting bundles only. A locally approved
# privileged gateway pin is NEVER reconciled, installed or rotated by this
# timer, including on an already-current SHA. Root must opt in separately.

if [[ "$current" == "$target" ]]; then
  # Missing helpers may be added by a self-update: complete them on the
  # next run. Altered existing binaries fail closed instead of being
  # overwritten without explicit administrative inspection.
  missing_helpers=0
  for name in $files; do
    src="$checkout/bin/$name"
    dst="$bin/$name"
    [[ -f "$src" && ! -L "$src" && ! -L "$dst" ]] || {
      echo "Invalid release helper: $name" >&2; exit 1;
    }
    if [[ ! -e "$dst" ]]; then
      missing_helpers=1
    elif ! cmp -s -- "$src" "$dst"; then
      echo "Installed helper drift detected: $name" >&2
      exit 1
    fi
  done
  [[ -f "$updater_src" && ! -L "$updater_src" && -f "$updater_dst" && ! -L "$updater_dst" ]] || {
    echo 'Invalid updater files' >&2; exit 1;
  }
  cmp -s -- "$updater_src" "$updater_dst" || {
    echo 'Installed updater drift detected' >&2; exit 1;
  }
  if [[ "$missing_helpers" -eq 0 ]]; then
    echo "Agent already current: $target"
    echo "PASS: hosting bundle staged; privileged gateway pin unchanged"
    exit 0
  fi
  echo "Completing missing release helpers at $target"
fi

stage="$(mktemp -d "$root/.agent-update-XXXXXXXX")"
mkdir -m 0700 "$stage/backup"
timer_stopped=0
replacing=0
self_replaced=0
finished=0

# Atomic replacement is essential: bash must keep reading the old inode
# while this updater is still executing. A direct 'install' to the live path
# could truncate the running script.
replace_updater() {
  local source="$1"
  local temporary
  temporary="$(mktemp "$bin/.self-update-agent.XXXXXXXX")"
  if ! install -m 0750 -o root -g root "$source" "$temporary"; then
    rm -f -- "$temporary"
    return 1
  fi
  if ! mv -f -- "$temporary" "$updater_dst"; then
    rm -f -- "$temporary"
    return 1
  fi
}
cleanup() {
  rc=$?
  trap - EXIT
  if [[ "$replacing" -eq 1 && "$finished" -eq 0 ]]; then
    echo 'Update failed; restoring previous binaries' >&2
    # Restore the previous entrypoint first, before removing new helpers.
    if [[ -f "$stage/backup/distributed-agent.php" ]]; then
      install -m 0755 -o root -g root "$stage/backup/distributed-agent.php" "$bin/distributed-agent.php" || true
    fi
    for name in $files; do
      [[ "$name" == "distributed-agent.php" ]] && continue
      if [[ -f "$stage/backup/$name" ]]; then
        install -m 0755 -o root -g root "$stage/backup/$name" "$bin/$name" || true
      else
        rm -f "$bin/$name"
      fi
    done
    systemctl start devmcp-agent.service || true
  fi
  if [[ "$self_replaced" -eq 1 && "$finished" -eq 0 ]]; then
    replace_updater "$stage/backup-self-update-agent.sh" || true
  fi
  if [[ "$timer_stopped" -eq 1 ]]; then
    systemctl start devmcp-agent.timer || true
  fi
  rm -rf -- "$stage"
  exit "$rc"
}
trap cleanup EXIT
for name in $files; do
  src="$checkout/bin/$name"
  dst="$bin/$name"
  [[ -f "$src" && ! -L "$src" && ! -L "$dst" ]] || { echo "Invalid source: $name" >&2; exit 1; }
  php -l "$src" >/dev/null
  install -m 0600 "$src" "$stage/$name"
  if [[ -f "$dst" ]]; then cp -p "$dst" "$stage/backup/$name"; fi
done
# The updater itself is deliberately part of the fixed release allowlist.
# New *future* PHP helpers can therefore be added to the script in main
# without another manual bootstrap on every remote host.
[[ -f "$updater_src" && ! -L "$updater_src" && -f "$updater_dst" && ! -L "$updater_dst" ]] || {
  echo 'Missing or linked self-updater artifact' >&2
  exit 1
}
bash -n "$updater_src"
install -m 0600 "$updater_src" "$stage/next-self-update-agent.sh"
cp -p "$updater_dst" "$stage/backup-self-update-agent.sh"
systemctl stop devmcp-agent.timer
timer_stopped=1
systemctl stop devmcp-agent.service
replacing=1
# Install dependencies before the executable entrypoint. The old agent timer may
# have left an in-flight activation: never expose a new entrypoint while its
# required helpers are absent. A restart failure rolls back the whole set.
for name in $files; do
  [[ "$name" == "distributed-agent.php" ]] && continue
  install -m 0755 -o root -g root "$stage/$name" "$bin/$name"
done
install -m 0755 -o root -g root "$stage/distributed-agent.php" "$bin/distributed-agent.php"
systemctl start devmcp-agent.service
systemctl start devmcp-agent.timer
# Install the future updater atomically *after* the active agent is healthy,
# but *before* committing the version. Rollback also restores this script.
replace_updater "$stage/next-self-update-agent.sh"
self_replaced=1
# Commit the version only after service, timer and updater have recovered.
printf '%s\n' "$target" > "$stage/revision"
# Update the revision marker by atomic rename on the same filesystem.
# A crash must not leave a truncated marker that blocks later recovery.
marker_tmp="$(mktemp "$root/.installed-revision.XXXXXXXX")"
if ! install -m 0644 -o root -g root "$stage/revision" "$marker_tmp"; then
  rm -f -- "$marker_tmp"
  exit 1
fi
if ! mv -f -- "$marker_tmp" "$marker"; then
  rm -f -- "$marker_tmp"
  exit 1
fi
finished=1
timer_stopped=0
echo "PASS: agent updated to $target; existing local authorizations preserved"
# Privileged gateway upgrades require a separate local-root approval.
# Staging a new bundle MUST NOT update the hosting-active pin.
echo "PASS: hosting bundle staged; privileged gateway pin unchanged"
