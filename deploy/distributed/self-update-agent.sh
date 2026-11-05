#!/usr/bin/env bash
# Approved agent binaries only. No edits to identities or local policies.
set -euo pipefail
umask 077
repo=https://github.com/Cowprod/devMcp.git
checkout=/var/lib/devmcp-agent-update/repository
root=/var/lib/devmcp-agent-update
bin=/opt/devmcp-agent/bin
marker=/var/lib/devmcp-agent-update/installed-revision
files="distributed-agent.php inspect-workspace.php agent-preview-port-probe.php remote-readonly-actions.php remote-job-policy.php remote-local-policy.php"
[[ "$(id -u)" -eq 0 && "$#" -eq 0 ]] || { echo 'Requires root; no arguments' >&2; exit 2; }
[[ -f /etc/devmcp/agent.php && ! -L /etc/devmcp/agent.php ]] || { echo 'Agent not enrolled' >&2; exit 1; }
[[ -d "$root" && ! -L "$root" && -d "$bin" && ! -L "$bin" ]] || { echo 'Invalid agent directories' >&2; exit 1; }
exec 9>/run/lock/devmcp-agent-update.lock
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
if [[ -f "$marker" ]]; then
  [[ ! -L "$marker" ]] || exit 1
  current="$(cat "$marker")"
  [[ "$current" =~ ^[0-9a-f]{40}$ ]] || exit 1
  [[ "$current" != "$target" ]] || { echo "Agent already current: $target"; exit 0; }
  git -C "$checkout" merge-base --is-ancestor "$current" "$target" || {
    echo 'Non fast-forward update refused' >&2; exit 1;
  }
fi
git -C "$checkout" -c core.hooksPath=/dev/null checkout --quiet --detach "$target"

stage="$(mktemp -d "$root/.agent-update-XXXXXXXX")"
mkdir -m 0700 "$stage/backup"
timer_stopped=0
replacing=0
finished=0
cleanup() {
  rc=$?
  trap - EXIT
  if [[ "$replacing" -eq 1 && "$finished" -eq 0 ]]; then
    echo 'Update failed; restoring previous binaries' >&2
    for name in $files; do
      if [[ -f "$stage/backup/$name" ]]; then
        install -m 0755 -o root -g root "$stage/backup/$name" "$bin/$name" || true
      else
        rm -f "$bin/$name"
      fi
    done
    systemctl start devmcp-agent.service || true
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
systemctl stop devmcp-agent.timer
timer_stopped=1
systemctl stop devmcp-agent.service
replacing=1
for name in $files; do install -m 0755 -o root -g root "$stage/$name" "$bin/$name"; done
systemctl start devmcp-agent.service
systemctl start devmcp-agent.timer
# Commit the version only after service and timer both recover.
printf '%s\n' "$target" > "$stage/revision"
install -m 0644 -o root -g root "$stage/revision" "$marker"
finished=1
timer_stopped=0
echo "PASS: agent updated to $target; local authorizations unchanged"
