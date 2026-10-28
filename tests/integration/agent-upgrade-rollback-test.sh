#!/usr/bin/env bash
# Run only on a disposable CI host. Mocks network/systemd, not the PHP linter.
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo 'Run as root in disposable CI' >&2; exit 1; }
[[ ! -e /etc/devmcp && ! -e /opt/devmcp-agent ]] || { echo 'Refusing non-disposable host' >&2; exit 1; }
upgrade_script="${1:-deploy/distributed/upgrade-agent-readonly.sh}"
[[ -f "$upgrade_script" ]] || { echo 'Upgrade script missing' >&2; exit 1; }
scratch="$(mktemp -d)"
[[ "$(stat -c %u /opt)" == 0 ]] || { echo 'CI /opt must be root owned' >&2; exit 1; }
original_opt_mode="$(stat -c %a /opt)"
chmod go-w /opt
export DEVMCP_UPGRADE_TEST_DIR="$scratch"
trap 'rc=$?; echo "FAIL: disposable upgrade fixture line $LINENO (rc=$rc)" >&2; for log in "$scratch"/*.log; do if [[ -f "$log" ]]; then echo "--- $log ---" >&2; tail -n 25 "$log" >&2; fi; done' ERR
trap 'rm -rf /etc/devmcp /opt/devmcp-agent "$scratch"; chmod "$original_opt_mode" /opt' EXIT
mkdir -p /etc/devmcp /opt/devmcp-agent/bin "$scratch/mocks"
printf '%s\n' '<?php return [];' > /etc/devmcp/agent.php
printf '%s\n' '<?php // old agent' > /opt/devmcp-agent/bin/distributed-agent.php
printf '%s\n' '<?php // old local policy' > /opt/devmcp-agent/bin/remote-local-policy.php
cat > "$scratch/mocks/curl" <<'MOCK'
#!/usr/bin/env bash
set -eu
output=''
while (($#)); do
  if [[ "$1" == '-o' ]]; then output="$2"; shift 2; else shift; fi
done
[[ "$output" != '' ]] || exit 66
case "$(basename "$output")" in
  distributed-agent.php|inspect-workspace.php) printf '%s\n' '<?php // upgraded agent' > "$output" ;;
  remote-job-policy.php) printf '%s\n' '<?php function remoteJobAllowed(){return true;} function remoteWriteSafeRelativePath(){return true;}' > "$output" ;;
  remote-local-policy.php)
    if [[ -f "$DEVMCP_UPGRADE_TEST_DIR/missing-helper" ]]; then
      printf '%s\n' '<?php function remoteLocalPolicyCheck(){return true;}' > "$output"
    else
      printf '%s\n' '<?php function remoteLocalPolicyFileTrusted(){return true;} function remoteLocalPolicyCheck(){return true;}' > "$output"
    fi ;;
  remote-readonly-actions.php) printf '%s\n' '<?php function remoteWorkspaceAction(){return [];}' > "$output" ;;
  remote-workspace-write-text.php) printf '%s\n' '<?php function remoteWorkspaceWriteText(){return [];}' > "$output" ;;
  remote-job-dispatch.php) printf '%s\n' '<?php function remoteAuthorizedJobDispatch(){return [];}' > "$output" ;;
  *) exit 67 ;;
esac
MOCK
cat > "$scratch/mocks/systemctl" <<'MOCK'
#!/usr/bin/env bash
set -eu
state="$DEVMCP_UPGRADE_TEST_DIR"
if [[ "$1" == 'is-active' && "${3:-}" == 'devmcp-agent.timer' ]]; then
  test -f "$state/timer-active"; exit $?
fi
if [[ "$1" == 'is-active' && "${3:-}" == 'devmcp-agent.service' ]]; then
  test -f "$state/service-active"; exit $?
fi
if [[ "$1" == 'stop' && "$2" == 'devmcp-agent.timer' ]]; then
  rm -f "$state/timer-active"; exit 0
fi
if [[ "$1" == 'start' && "$2" == 'devmcp-agent.timer' ]]; then
  touch "$state/timer-active"; exit 0
fi
if [[ "$1" == 'start' && "$2" == 'devmcp-agent.service' ]]; then
  test ! -f "$state/service-fail"; exit $?
fi
exit 68
MOCK
chmod 0755 "$scratch/mocks/curl" "$scratch/mocks/systemctl"
export PATH="$scratch/mocks:$PATH"
sha=2b2736634d6a90524a4cf71b7589e6e90bb7920a
touch "$scratch/timer-active" "$scratch/missing-helper"
if bash "$upgrade_script" "$sha" > "$scratch/missing.log" 2>&1; then echo 'FAIL: incomplete bundle accepted'; exit 1; fi
grep -q 'missing remoteLocalPolicyFileTrusted' "$scratch/missing.log"
grep -q 'old agent' /opt/devmcp-agent/bin/distributed-agent.php
test -f "$scratch/timer-active"
echo 'PASS: incomplete pinned bundle rejected before install'
rm "$scratch/missing-helper"
touch "$scratch/service-active"
if bash "$upgrade_script" "$sha" > "$scratch/active.log" 2>&1; then echo 'FAIL: active service permitted upgrade'; exit 1; fi
grep -q 'Agent service is running' "$scratch/active.log"
grep -q 'old agent' /opt/devmcp-agent/bin/distributed-agent.php
test -f "$scratch/timer-active"
echo 'PASS: active agent is not interrupted and timer resumes'
rm "$scratch/service-active"
touch "$scratch/service-fail"
if bash "$upgrade_script" "$sha" > "$scratch/failure.log" 2>&1; then echo 'FAIL: service failure accepted'; exit 1; fi
grep -q 'restoring previous binaries' "$scratch/failure.log"
grep -q 'old agent' /opt/devmcp-agent/bin/distributed-agent.php
grep -q 'old local policy' /opt/devmcp-agent/bin/remote-local-policy.php
test ! -e /opt/devmcp-agent/bin/remote-job-policy.php
test -f "$scratch/timer-active"
echo 'PASS: failed service restores binaries and timer'
rm "$scratch/service-fail"
bash "$upgrade_script" "$sha" > "$scratch/success.log" 2>&1
grep -q 'PASS: agent bundle installed' "$scratch/success.log"
grep -q 'upgraded agent' /opt/devmcp-agent/bin/distributed-agent.php
test -f "$scratch/timer-active"
snapshot="$(sed -n 's/^ROLLBACK_SNAPSHOT: //p' "$scratch/success.log")"
test -f "$snapshot/distributed-agent.php"
grep -q 'old agent' "$snapshot/distributed-agent.php"
grep -q 'remote-job-policy.php' "$snapshot/.originally-absent"
echo 'PASS: successful upgrade retains private rollback snapshot'
