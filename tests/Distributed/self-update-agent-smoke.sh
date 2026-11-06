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
  RemoteHostingGitEvidence RemoteHostingGitProof
  RemoteHostingGitTreeEvidence RemoteHostingLocalPolicy
  RemoteHostingPortEvidence RemoteHostingReleaseFilesystemEvidence
  RemoteHostingReleasePlan RemoteHostingReleaseStager RemoteHostingUnitDraft
  RemoteHostingUnitInstaller RemoteHostingWorkspaceEvidence
  RemoteHostingZeroTierMembership RemoteHostingZeroTierNetworkPin
)
for name in "${hosting_classes[@]}"; do
  printf '%s\n' '<?php declare(strict_types=1);' > "$tmp/state/repository/src/Project/$name.php"
done

for name in distributed-agent.php inspect-workspace.php agent-preview-port-probe.php \
  remote-readonly-actions.php remote-job-policy.php remote-local-policy.php; do
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
if [[ "${1:-}" == -c && "${2:-}" == %u ]]; then
  echo 0
else
  exec /usr/bin/stat "$@"
fi
STUB
chmod +x "$tmp/fake/"*
export PATH="$tmp/fake:$PATH"

# 1) Idempotent on complete matching SHA.
bash "$tmp/updater.sh" > "$tmp/out1"
grep -Fq "Agent already current: $target" "$tmp/out1"
[[ ! -s "$SYSTEMCTL_LOG" ]]
bundle="$tmp/agent-bin/hosting-bundles/$target"
[[ -f "$bundle/Project/RemoteHostingUnitInstaller.php" ]]
[[ "$(find "$bundle" -type f | wc -l)" -eq "${#hosting_classes[@]}" ]]

# 2) Same SHA, one newly required helper missing: complete it unattended.
rm "$tmp/agent-bin/remote-job-policy.php"
bash "$tmp/updater.sh" > "$tmp/out2"
grep -Fq "Completing missing release helpers at $target" "$tmp/out2"
grep -Fq "PASS: agent updated to $target" "$tmp/out2"
cmp -s "$tmp/state/repository/bin/remote-job-policy.php" "$tmp/agent-bin/remote-job-policy.php"
[[ "$(cat "$tmp/state/installed-revision")" == "$target" ]]
grep -Fq "stop devmcp-agent.timer" "$SYSTEMCTL_LOG"
grep -Fq "start devmcp-agent.service" "$SYSTEMCTL_LOG"

# 3) Same SHA but existing binary drift: fail closed before stopping services.
: > "$SYSTEMCTL_LOG"
printf '%s\n' '<?php return false;' > "$tmp/agent-bin/remote-job-policy.php"
if bash "$tmp/updater.sh" > "$tmp/out3" 2>&1; then
  echo 'FAIL: corrupted installed helper was silently overwritten' >&2
  exit 1
fi
grep -Fq 'Installed helper drift detected' "$tmp/out3"
[[ ! -s "$SYSTEMCTL_LOG" ]]
# 4) An altered, already staged source bundle fails closed without stopping
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
