#!/usr/bin/env bash
# Upgrade an enrolled agent from an immutable commit without changing policy.
# Stage and check the complete bundle, then restore prior binaries on failure.
set -euo pipefail
[[ "$(id -u)" -eq 0 && "$#" -eq 1 && "$1" =~ ^[a-f0-9]{40}$ ]] || { echo "Usage: sudo bash upgrade-agent-readonly.sh COMMIT_SHA" >&2; exit 2; }
[[ -f /etc/devmcp/agent.php ]] || { echo "Agent not enrolled" >&2; exit 1; }
agent_root=/opt/devmcp-agent
agent_dir=$agent_root/bin
for directory in /opt "$agent_root" "$agent_dir"; do
  [[ -d "$directory" && ! -L "$directory" && "$(stat -c %u "$directory")" == 0 ]] || { echo "Untrusted agent directory: $directory" >&2; exit 1; }
  mode="$(stat -c %a "$directory")"
  (( (8#$mode & 8#022) == 0 )) || { echo "Group/world writable agent directory: $directory" >&2; exit 1; }
done
# Serialize independent operators' upgrades: overlapping installs and
# rollbacks must never manipulate the same timer or binary set concurrently.
# Keep FD 9 open until the EXIT trap completes.
lock_file="$agent_root/.upgrade.lock"
if [[ -e "$lock_file" || -L "$lock_file" ]]; then
  [[ -f "$lock_file" && ! -L "$lock_file" && "$(stat -c %u "$lock_file")" == 0 && "$(stat -c %h "$lock_file")" == 1 ]] || { echo "Untrusted agent upgrade lock" >&2; exit 1; }
  lock_mode="$(stat -c %a "$lock_file")"
  (( (8#$lock_mode & 8#022) == 0 )) || { echo "Writable agent upgrade lock" >&2; exit 1; }
fi
( umask 077; : >> "$lock_file" )
exec 9>>"$lock_file"
flock -n 9 || { echo "Another agent upgrade is running" >&2; exit 1; }
base="https://raw.githubusercontent.com/Cowprod/devMcp/$1/bin"
tmp="$(mktemp -d)"
files=(distributed-agent.php inspect-workspace.php remote-readonly-actions.php remote-job-policy.php remote-local-policy.php remote-job-dispatch.php remote-workspace-write-text.php)
installed=()
timer_was_active=0
backup_dir=
cleanup() {
  local exit_code=$?
  if (( exit_code != 0 && ${#installed[@]} > 0 )); then
    echo "ERROR: agent upgrade failed; restoring previous binaries" >&2
    local file
    for file in "${installed[@]}"; do
      if [[ -f "$backup_dir/original/$file" ]]; then
        cp -p "$backup_dir/original/$file" "$agent_dir/$file" || echo "ERROR: manual restore required: $file" >&2
      else
        rm -f "$agent_dir/$file" || echo "ERROR: manual removal required: $file" >&2
      fi
    done
    echo "RECOVERY_SNAPSHOT: $backup_dir/original" >&2
  fi
  if (( timer_was_active )); then
    systemctl start devmcp-agent.timer || echo "ERROR: manual timer restart required" >&2
  fi
  rm -rf "$tmp"
}
trap cleanup EXIT
for file in "${files[@]}"; do
  curl --proto '=https' --tlsv1.2 --fail --silent --show-error --location --max-time 30 "$base/$file" -o "$tmp/$file"
  php -l "$tmp/$file" >/dev/null
done
# A syntactically valid legacy release can still omit mandatory dispatcher
# and trust-check functions. Check dependencies before replacing any binary.
php -r '
  $dir = $argv[1];
  foreach (["inspect-workspace.php", "remote-job-policy.php", "remote-local-policy.php", "remote-readonly-actions.php",
      "remote-workspace-write-text.php", "remote-job-dispatch.php"] as $file) {
      require_once $dir . "/" . $file;
  }
  foreach (["inspectWorkspace", "remoteLocalPolicyFileTrusted", "remoteLocalPolicyCheck", "remoteJobAllowed",
      "remoteWriteSafeRelativePath", "remoteWorkspaceAction", "remoteWorkspaceWriteText",
      "remoteAuthorizedJobDispatch"] as $name) {
      if (!function_exists($name)) {
          fwrite(STDERR, "Incomplete agent bundle: missing $name\n");
          exit(1);
      }
  }
' "$tmp"
# Prevent the 10-second timer from launching an agent with half-replaced
# files. Never interrupt an active one-shot job: refuse and retry later.
if systemctl is-active --quiet devmcp-agent.timer; then
  timer_was_active=1
  systemctl stop devmcp-agent.timer
fi
if systemctl is-active --quiet devmcp-agent.service; then
  echo "Agent service is running; refusing in-place upgrade" >&2
  exit 1
fi
mkdir -m 0700 "$tmp/backup"
for file in "${files[@]}"; do
  dest="$agent_dir/$file"
  if [[ -e "$dest" || -L "$dest" ]]; then
    [[ -f "$dest" && ! -L "$dest" && "$(stat -c %u "$dest")" == 0 && "$(stat -c %h "$dest")" == 1 ]] || { echo "Untrusted existing binary: $file" >&2; exit 1; }
    mode="$(stat -c %a "$dest")"
    (( (8#$mode & 8#022) == 0 )) || { echo "Writable existing binary: $file" >&2; exit 1; }
    cp -p "$dest" "$tmp/backup/$file"
  else
    printf '%s\n' "$file" >> "$tmp/backup/.originally-absent"
  fi
done
# Keep the snapshot outside temporary staging *before* replacing any file.
# It remains available even if automatic rollback subsequently fails.
backup_dir="$(mktemp -d "$agent_root/.upgrade-backup.XXXXXXXX")"
mv "$tmp/backup" "$backup_dir/original"
for file in "${files[@]}"; do
  installed+=("$file")
  install -m 0755 -o root -g root "$tmp/$file" "$agent_dir/$file"
done
systemctl start devmcp-agent.service
if (( timer_was_active )); then
  systemctl start devmcp-agent.timer
  timer_was_active=0
fi
# Preserve a private rollback snapshot after success; no policy file is copied.
printf 'PASS: agent bundle installed and service started; no write policy changed.\n'
printf 'ROLLBACK_SNAPSHOT: %s/original\n' "$backup_dir"
