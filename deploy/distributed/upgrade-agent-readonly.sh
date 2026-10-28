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
base="https://raw.githubusercontent.com/Cowprod/devMcp/$1/bin"
tmp="$(mktemp -d)"
files=(distributed-agent.php inspect-workspace.php remote-readonly-actions.php remote-job-policy.php remote-local-policy.php remote-job-dispatch.php remote-workspace-write-text.php)
installed=()
timer_was_active=0
cleanup() {
  local exit_code=$?
  if (( exit_code != 0 && ${#installed[@]} > 0 )); then
    echo "ERROR: agent upgrade failed; restoring previous binaries" >&2
    local file
    for file in "${installed[@]}"; do
      if [[ -f "$tmp/backup/$file" ]]; then
        cp -p "$tmp/backup/$file" "$agent_dir/$file" || echo "ERROR: manual restore required: $file" >&2
      else
        rm -f "$agent_dir/$file" || echo "ERROR: manual removal required: $file" >&2
      fi
    done
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
  foreach (["remote-job-policy.php", "remote-local-policy.php", "remote-readonly-actions.php",
      "remote-workspace-write-text.php", "remote-job-dispatch.php"] as $file) {
      require_once $dir . "/" . $file;
  }
  foreach (["remoteLocalPolicyFileTrusted", "remoteLocalPolicyCheck", "remoteJobAllowed",
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
    [[ -f "$dest" && ! -L "$dest" && "$(stat -c %u "$dest")" == 0 ]] || { echo "Untrusted existing binary: $file" >&2; exit 1; }
    cp -p "$dest" "$tmp/backup/$file"
  else
    printf '%s\n' "$file" >> "$tmp/backup/.originally-absent"
  fi
done
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
backup_dir="$(mktemp -d "$agent_root/.upgrade-backup.XXXXXXXX")"
mv "$tmp/backup" "$backup_dir/original"
printf 'PASS: agent bundle installed and service started; no write policy changed.\n'
printf 'ROLLBACK_SNAPSHOT: %s/original\n' "$backup_dir"
