#!/usr/bin/env bash
# Isolated same-revision self-update smoke test. No real systemd, GitHub,
# /etc/devmcp or agent installation is touched.
set -euo pipefail
tmp="$(mktemp -d)"
trap 'rm -rf -- "$tmp"' EXIT
mkdir -p "$tmp/fake" "$tmp/etc" "$tmp/state/repository/.git" \
  "$tmp/state/repository/bin" "$tmp/state/repository/deploy/distributed" "$tmp/agent-bin"
printf '%s\n' '<?php return [];' > "$tmp/etc/agent.php"
export SYSTEMCTL_LOG="$tmp/systemctl.log"
: > "$SYSTEMCTL_LOG"
target=1111111111111111111111111111111111111111
printf '%s\n' "$target" > "$tmp/state/installed-revision"

# Substitute only sandbox paths in a copy of the production updater.
sed -e "s|/var/lib/devmcp-agent-update|$tmp/state|g" \
  -e "s|/opt/devmcp-agent/bin|$tmp/agent-bin|g" \
  -e "s|/etc/devmcp/agent.php|$tmp/etc/agent.php|g" \
  -e "s|/etc/devmcp/hosting-policy.json|$tmp/etc/hosting-policy.json|g" \
  -e "s|/run/lock/devmcp-agent-update.lock|$tmp/update.lock|g" \
  deploy/distributed/self-update-agent.sh > "$tmp/updater.sh"
bash -n "$tmp/updater.sh"
cp "$tmp/updater.sh" "$tmp/state/repository/deploy/distributed/self-update-agent.sh"
cp "$tmp/updater.sh" "$tmp/agent-bin/self-update-agent.sh"
cp deploy/distributed/stage-hosting-source-bundle.sh \
  "$tmp/state/repository/deploy/distributed/stage-hosting-source-bundle.sh"
mkdir -p "$tmp/state/repository/src/Project"
hosting_classes=(
  RemoteHostingActivationContract RemoteHostingActivationJournal
  RemoteHostingActivationRecoveryEvidence RemoteHostingBindEvidence
  RemoteHostingGitEvidence RemoteHostingGitProof RemoteHostingGitRefresh
  RemoteHostingInitialActivation
  RemoteHostingGitTreeEvidence RemoteHostingLocalPolicy RemoteHostingLocalPreparation
  RemoteHostingPortEvidence RemoteHostingPeerEvidence RemoteHostingPendingWatchdog RemoteHostingLabBootstrap RemoteHostingReleaseFilesystemEvidence
  RemoteHostingReleasePlan RemoteHostingReleaseStager RemoteHostingReleaseSwap
  RemoteHostingSystemdControl RemoteHostingUnitDraft
  RemoteHostingUnitInstaller RemoteHostingWorkspaceEvidence
  RemoteHostingZeroTierMembership RemoteHostingZeroTierNetworkPin
)
for name in "${hosting_classes[@]}"; do
  printf '%s\n' '<?php declare(strict_types=1);' > "$tmp/state/repository/src/Project/$name.php"
done

cp bin/devmcp-hosting-root "$tmp/state/repository/bin/devmcp-hosting-root"
cp bin/devmcp-hosting-gateway "$tmp/state/repository/bin/devmcp-hosting-gateway"
for name in distributed-agent.php inspect-workspace.php agent-preview-port-probe.php \
  remote-readonly-actions.php remote-job-policy.php remote-local-policy.php \
  hosting-queue-policy.php remote-hosting-queue.php; do
  printf '%s\n' '<?php return true;' > "$tmp/state/repository/bin/$name"
  cp "$tmp/state/repository/bin/$name" "$tmp/agent-bin/$name"
done

cat > "$tmp/fake/git" <<'STUB'
#!/usr/bin/env bash
case " $* " in
  *"config --get remote.origin.url"*) echo https://github.com/Cowprod/devMcp.git ;;
  *"status --porcelain --untracked-files=no"*) : ;;
  *"fetch --quiet --no-tags origin main"*) : ;;
  *"rev-parse refs/remotes/origin/main"*) echo 1111111111111111111111111111111111111111 ;;
  *"rev-parse HEAD"*) echo 1111111111111111111111111111111111111111 ;;
  *"checkout --quiet --detach"*) : ;;
  *"merge-base --is-ancestor"*) : ;;
  *) echo "Unmocked git action: $*" >&2; exit 9 ;;
esac
STUB
cat > "$tmp/fake/systemctl" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$SYSTEMCTL_LOG"
STUB
cat > "$tmp/fake/id" <<'STUB'
#!/usr/bin/env bash
if [[ "${1:-}" == -u ]]; then echo 0; else /usr/bin/id "$@"; fi
STUB
cat > "$tmp/fake/install" <<'STUB'
#!/usr/bin/env bash
args=()
while (( $# )); do
  case "$1" in
    -o|-g) shift 2 ;;
    *) args+=("$1"); shift ;;
  esac
done
exec /usr/bin/install "${args[@]}"
STUB
cat > "$tmp/fake/stat" <<'STUB'
#!/usr/bin/env bash
# The smoke sandbox simulates the root-owned metadata of the real installation.
# Preserve real mode/link-count checks while simulating root ownership
# for files inside this disposable non-root CI sandbox.
if [[ "${1:-}" == -c ]]; then
  case "${2:-}" in
    %u) echo 0; exit 0 ;;
    %u:%g) echo 0:0; exit 0 ;;
    %u:%g:%a:%h)
      value="$(/usr/bin/stat -c '%a:%h' "$3")" || exit
      echo "0:0:$value"
      exit 0
      ;;
  esac
fi
exec /usr/bin/stat "$@"
STUB
chmod +x "$tmp/fake/"*
export PATH="$tmp/fake:$PATH"

# Simulate an already opted-in hosting lab with a pinned privileged gateway.
# Even an old self-updater must not promote it to the newly checked-out SHA.
printf '%s\n' '{"enabled":true}' > "$tmp/etc/hosting-policy.json"
export HOSTING_INSTALLER_LOG="$tmp/hosting-installer.log"
cat > "$tmp/state/repository/deploy/distributed/install-hosting-gateway.sh" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$HOSTING_INSTALLER_LOG"
exit 0
STUB
chmod +x "$tmp/state/repository/deploy/distributed/install-hosting-gateway.sh"
oldpin=2222222222222222222222222222222222222222
ln -s "hosting-bundles/$oldpin" "$tmp/agent-bin/hosting-active"

# The privileged installer must reject the legacy unattended SHA-only call.
if bash deploy/distributed/install-hosting-gateway.sh "$target" > "$tmp/installer-denied" 2>&1; then
  echo 'FAIL: privileged installer accepted legacy updater argv' >&2
  exit 1
fi
grep -Fq -- '--root-approved' "$tmp/installer-denied"

# Malicious lock entries must fail before even fetching source or staging
# root-owned helpers; the victim is never opened or truncated.
lock="$tmp/update.lock"
printf 'preserve-me\\n' > "$tmp/lock-victim"
chmod 0600 "$tmp/lock-victim"
ln -s "$tmp/lock-victim" "$lock"
if bash "$tmp/updater.sh" > "$tmp/lock-symlink-error" 2>&1; then
  echo 'FAIL: linked privileged updater lock accepted' >&2; exit 1
fi
grep -Fq 'Untrusted agent updater lock file' "$tmp/lock-symlink-error"
[[ "$(cat "$tmp/lock-victim")" == 'preserve-me\\n' ]] || {
  echo 'FAIL: updater truncated linked lock victim' >&2; exit 1
}
rm "$lock"
ln "$tmp/lock-victim" "$lock"
if bash "$tmp/updater.sh" > "$tmp/lock-hardlink-error" 2>&1; then
  echo 'FAIL: hard-linked privileged updater lock accepted' >&2; exit 1
fi
grep -Fq 'Untrusted agent updater lock file' "$tmp/lock-hardlink-error"
rm "$lock"
[[ ! -s "$SYSTEMCTL_LOG" ]]

# 1) Idempotent on complete matching SHA.
bash "$tmp/updater.sh" > "$tmp/out1"
grep -Fq "Agent already current: $target" "$tmp/out1"
[[ ! -s "$SYSTEMCTL_LOG" ]]
[[ -f "$lock" && ! -L "$lock" && "$(stat -c '%a:%h' "$lock")" == '600:1' ]]
# A shared/excessively permissive inode cannot be treated as our lock.
chmod 0666 "$lock"
if bash "$tmp/updater.sh" > "$tmp/lock-mode-error" 2>&1; then
  echo 'FAIL: writable privileged updater lock accepted' >&2; exit 1
fi
grep -Fq 'Untrusted agent updater lock file' "$tmp/lock-mode-error"
chmod 0600 "$lock"
# The updater must not fetch, stage or touch services while another invocation
# owns the lock. The lock inode is persistent (never truncated or replaced).
exec 8<>"$lock"
flock -n 8
bash "$tmp/updater.sh" > "$tmp/out-locked"
grep -Fxq 'Update already running' "$tmp/out-locked"
[[ ! -s "$SYSTEMCTL_LOG" ]]
flock -u 8
exec 8>&-
[[ ! -e "$HOSTING_INSTALLER_LOG" ]]
[[ "$(readlink "$tmp/agent-bin/hosting-active")" == "hosting-bundles/$oldpin" ]]
bundle="$tmp/agent-bin/hosting-bundles/$target"
[[ -f "$bundle/Project/RemoteHostingUnitInstaller.php" ]]
[[ -f "$bundle/devmcp-hosting-root" ]]
[[ -f "$bundle/devmcp-hosting-gateway" ]]
cmp -s bin/devmcp-hosting-root "$bundle/devmcp-hosting-root"
cmp -s bin/devmcp-hosting-gateway "$bundle/devmcp-hosting-gateway"
[[ "$(find "$bundle" -type f | wc -l)" -eq "$((${#hosting_classes[@]} + 2))" ]]

# 2) Same SHA, one newly required helper missing: complete it unattended.
rm "$tmp/agent-bin/remote-job-policy.php"
bash "$tmp/updater.sh" > "$tmp/out2"
grep -Fq "Completing missing release helpers at $target" "$tmp/out2"
grep -Fq "PASS: agent updated to $target" "$tmp/out2"
cmp -s "$tmp/state/repository/bin/remote-job-policy.php" "$tmp/agent-bin/remote-job-policy.php"
[[ "$(cat "$tmp/state/installed-revision")" == "$target" ]]
grep -Fq "stop devmcp-agent.timer" "$SYSTEMCTL_LOG"
grep -Fq "start devmcp-agent.service" "$SYSTEMCTL_LOG"
[[ ! -e "$HOSTING_INSTALLER_LOG" ]]
[[ "$(readlink "$tmp/agent-bin/hosting-active")" == "hosting-bundles/$oldpin" ]]

# 2b) A new agent revision stages code but never activates privileged code.
printf '%s\n' "$oldpin" > "$tmp/state/installed-revision"
bash "$tmp/updater.sh" > "$tmp/out2b"
grep -Fq "PASS: agent updated to $target" "$tmp/out2b"
[[ "$(cat "$tmp/state/installed-revision")" == "$target" ]]
[[ ! -e "$HOSTING_INSTALLER_LOG" ]]
[[ "$(readlink "$tmp/agent-bin/hosting-active")" == "hosting-bundles/$oldpin" ]]

# 3) Same SHA but existing binary drift: fail closed before stopping services.
: > "$SYSTEMCTL_LOG"
printf '%s\n' '<?php return false;' > "$tmp/agent-bin/remote-job-policy.php"
if bash "$tmp/updater.sh" > "$tmp/out3" 2>&1; then
  echo 'FAIL: corrupted installed helper was silently overwritten' >&2
  exit 1
fi
grep -Fq 'Installed helper drift detected' "$tmp/out3"
[[ ! -s "$SYSTEMCTL_LOG" ]]
# 4) A modified privileged executor is never silently repaired by the updater.
printf "\n# tampered\n" >> "$bundle/devmcp-hosting-root"
: > "$SYSTEMCTL_LOG"
if bash "$tmp/updater.sh" > "$tmp/out4cli" 2>&1; then
  echo "FAIL: modified hosting executor accepted" >&2
  exit 1
fi
grep -Fq "Hosting executor drift" "$tmp/out4cli"
[[ ! -s "$SYSTEMCTL_LOG" ]]
cp bin/devmcp-hosting-root "$bundle/devmcp-hosting-root"

# 5) An altered, already staged source bundle fails closed without stopping
# the live agent. A new release is never silently used to repair drift.
cp "$tmp/state/repository/bin/remote-job-policy.php" "$tmp/agent-bin/remote-job-policy.php"
printf '%s\n' '<?php return false;' > "$bundle/Project/RemoteHostingReleasePlan.php"
: > "$SYSTEMCTL_LOG"
if bash "$tmp/updater.sh" > "$tmp/out4" 2>&1; then
  echo 'FAIL: altered inert hosting source was silently replaced' >&2
  exit 1
fi
grep -Fq 'Hosting bundle drift: RemoteHostingReleasePlan' "$tmp/out4"
[[ ! -s "$SYSTEMCTL_LOG" ]]

# 5) Unknown files cannot be smuggled into a revision-pinned bundle.
cp "$tmp/state/repository/src/Project/RemoteHostingReleasePlan.php" \
  "$bundle/Project/RemoteHostingReleasePlan.php"
printf '%s\n' '<?php return true;' > "$bundle/Project/unreviewed.php"
if bash "$tmp/updater.sh" > "$tmp/out5" 2>&1; then
  echo 'FAIL: unexpected bundle file was accepted' >&2
  exit 1
fi
grep -Fq 'Unexpected hosting bundle inventory' "$tmp/out5"
[[ ! -s "$SYSTEMCTL_LOG" ]]
# 6) A Git tree symlink in an ancestor cannot redirect fixed class names.
mv "$tmp/state/repository/src/Project" "$tmp/state/repository/src/Project-original"
ln -s Project-original "$tmp/state/repository/src/Project"
if bash "$tmp/updater.sh" > "$tmp/out6" 2>&1; then
  echo 'FAIL: linked source directory accepted' >&2
  exit 1
fi
grep -Fq 'Untrusted hosting source directories' "$tmp/out6"
[[ ! -s "$SYSTEMCTL_LOG" ]]
echo 'PASS: agent self-update and immutable inert hosting bundle'
