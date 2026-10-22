#!/usr/bin/env bash
# devMcp agent bootstrap preflight. Does not enroll or start an agent.
set -euo pipefail

usage() {
  echo "Usage: sudo bash install-agent.sh --server https://ZT_IP --ca-file /path/to/ca.pem --token-stdin [--check-only]" >&2
  exit 2
}
SERVER=""
CHECK_ONLY=0
CA_FILE=""
TOKEN=""
TOKEN_STDIN=0
while (($#)); do
  case "$1" in
    --server) (($# >= 2)) || usage; SERVER="$2"; shift 2 ;;
    --ca-file) (($# >= 2)) || usage; CA_FILE="$2"; shift 2 ;;
    --token-stdin) TOKEN_STDIN=1; shift ;;
    --check-only) CHECK_ONLY=1; shift ;;
    *) usage ;;
  esac
done
[[ "$EUID" -eq 0 ]] || { echo "ERROR: root required" >&2; exit 1; }
[[ "$SERVER" =~ ^https://([0-9]{1,3}\.){3}[0-9]{1,3}(:[0-9]+)?$ ]] || {
  echo "ERROR: --server must be a literal ZeroTier IPv4 HTTPS URL" >&2; exit 1;
}
[[ -r /etc/os-release ]] || { echo "ERROR: /etc/os-release missing" >&2; exit 1; }
# shellcheck disable=SC1091
source /etc/os-release
case "${ID:-}" in ubuntu|debian) ;; *) echo "ERROR: unsupported OS: ${ID:-unknown}" >&2; exit 1;; esac
command -v systemctl >/dev/null || { echo "ERROR: systemd required" >&2; exit 1; }
command -v curl >/dev/null || { echo "ERROR: curl required" >&2; exit 1; }
command -v php >/dev/null || { echo "ERROR: PHP 8.3+ required" >&2; exit 1; }
php -r 'exit(PHP_VERSION_ID >= 80300 ? 0 : 1);' || {
  echo "ERROR: PHP 8.3+ required" >&2; exit 1;
}
command -v zerotier-cli >/dev/null || { echo "ERROR: ZeroTier required" >&2; exit 1; }
ZT_IP="${SERVER#https://}"
ZT_IP="${ZT_IP%%:*}"
ip -4 route get "$ZT_IP" | grep -Eq ' dev zt[^ ]+' || {
  echo "ERROR: server route is not via ZeroTier" >&2; exit 1;
}
echo "Preflight OK: ${PRETTY_NAME:-$ID}, PHP $(php -r 'echo PHP_VERSION;'), ZeroTier route to $ZT_IP"
if ((CHECK_ONLY)); then exit 0; fi
if (( ! TOKEN_STDIN )); then echo "ERROR: --token-stdin required" >&2; exit 1; fi
IFS= read -r TOKEN || true
[[ "$TOKEN" =~ ^[a-f0-9]{64}$ ]] || { echo "ERROR: valid enrollment token required" >&2; exit 1; }
[[ -f "$CA_FILE" && ! -L "$CA_FILE" ]] || { echo "ERROR: trusted CA file required" >&2; exit 1; }
[[ ! -e /etc/devmcp/agent.php && ! -e /etc/devmcp/agent-token ]] || {
  echo "ERROR: agent identity already exists; refusing overwrite" >&2; exit 1;
}
command -v python3 >/dev/null || { echo "ERROR: python3 required" >&2; exit 1; }
umask 077
TMPDIR_AGENT="$(mktemp -d)"
trap 'rm -rf "$TMPDIR_AGENT"' EXIT
printf '{"token":"%s"}' "$TOKEN" > "$TMPDIR_AGENT/request.json"
unset TOKEN
HTTP_CODE="$(curl --silent --show-error --output "$TMPDIR_AGENT/response.json" \
  --write-out '%{http_code}' --cacert "$CA_FILE" \
  --header 'Content-Type: application/json' --max-time 20 \
  --data-binary "@$TMPDIR_AGENT/request.json" \
  "$SERVER/v1/enrollment/redeem")" || { echo "ERROR: TLS or enrollment request failed" >&2; exit 1; }
[[ "$HTTP_CODE" == 200 ]] || { echo "ERROR: enrollment rejected (HTTP $HTTP_CODE)" >&2; exit 1; }
python3 - "$TMPDIR_AGENT/response.json" "$TMPDIR_AGENT/identity" <<'PY'
import json, re, sys
with open(sys.argv[1], encoding="utf-8") as f:
    data = json.load(f)
machine_id, credential = data.get("machine_id"), data.get("credential")
if not isinstance(machine_id, str) or not re.fullmatch(r"[a-f0-9]{32}", machine_id):
    sys.exit("ERROR: invalid machine identity response")
if not isinstance(credential, str) or not re.fullmatch(r"[a-f0-9]{64}", credential):
    sys.exit("ERROR: invalid credential response")
with open(sys.argv[2], "w", encoding="ascii") as f:
    f.write(machine_id + "\n" + credential + "\n")
PY
# Enrollment only. No agent process is started before local policy approval.
install -d -m 0700 -o root -g root /etc/devmcp
install -m 0600 -o root -g root "$CA_FILE" /etc/devmcp/central-ca.pem
machine_id="$(sed -n '1p' "$TMPDIR_AGENT/identity")"
sed -n '2p' "$TMPDIR_AGENT/identity" > /etc/devmcp/agent-token
chmod 0600 /etc/devmcp/agent-token
printf "<?php\\nreturn ['machine_id' => '%s', 'central_url' => '%s', 'policy_file' => '/etc/devmcp/agent-policy.php', 'credential_file' => '/etc/devmcp/agent-token', 'ca_file' => '/etc/devmcp/central-ca.pem', 'state_dir' => '/var/lib/devmcp-agent'];\\n" "$machine_id" "$SERVER" > /etc/devmcp/agent.php
chmod 0600 /etc/devmcp/agent.php
echo "Enrollment completed: machine $machine_id. Agent execution remains disabled pending local policy approval."

