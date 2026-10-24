#!/usr/bin/env bash
# devMcp agent bootstrap. Installs a connectivity-only agent, never remote execution.
set -euo pipefail

usage() {
  echo "Usage: sudo bash install-agent.sh --server https://ZT_IP (--ca-file /path/to/ca.pem | --cert-sha256 HEX --cert-url HTTPS_URL) --token-stdin [--check-only]" >&2
  exit 2
}
SERVER=""
CHECK_ONLY=0
CA_FILE=""
CERT_SHA256=""
CERT_URL=""
TOKEN=""
TOKEN_STDIN=0
while (($#)); do
  case "$1" in
    --server) (($# >= 2)) || usage; SERVER="$2"; shift 2 ;;
    --ca-file) (($# >= 2)) || usage; CA_FILE="$2"; shift 2 ;;
    --cert-sha256) (($# >= 2)) || usage; CERT_SHA256="$2"; shift 2 ;;
    --cert-url) (($# >= 2)) || usage; CERT_URL="$2"; shift 2 ;;
    --token-stdin) TOKEN_STDIN=1; shift ;;
    --check-only) CHECK_ONLY=1; shift ;;
    *) usage ;;
  esac
done
[[ "$EUID" -eq 0 ]] || { echo "ERROR: root required" >&2; exit 1; }
command -v ip >/dev/null || { echo "ERROR: iproute2 required" >&2; exit 1; }
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
php -r 'exit(extension_loaded("curl") ? 0 : 1);' || {
  echo "ERROR: PHP curl extension required (install php8.3-curl before enrollment)" >&2; exit 1;
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
if [[ -n "$CERT_SHA256" || -n "$CERT_URL" ]]; then
  [[ -z "$CA_FILE" && "$CERT_SHA256" =~ ^[a-fA-F0-9]{64}$ ]] || {
    echo "ERROR: provide a valid --cert-sha256 without --ca-file" >&2; exit 1;
  }
  [[ "$CERT_URL" == "$SERVER/devmcp-ca.crt" ]] || {
    echo "ERROR: certificate URL must be the fixed central certificate endpoint" >&2; exit 1;
  }
  CERT_TMP="$(mktemp)"
  trap 'rm -f "$CERT_TMP"' EXIT
  curl --insecure --proto '=https' --silent --show-error --fail --max-time 20 --output "$CERT_TMP" "$CERT_URL" || {
    echo "ERROR: cannot retrieve certificate" >&2; exit 1;
  }
  ACTUAL_SHA256="$(sha256sum "$CERT_TMP" | cut -d' ' -f1)"
  [[ "${ACTUAL_SHA256,,}" == "${CERT_SHA256,,}" ]] || {
    echo "ERROR: certificate SHA-256 mismatch" >&2; exit 1;
  }
  openssl x509 -in "$CERT_TMP" -noout >/dev/null || { echo "ERROR: invalid X509 certificate" >&2; exit 1; }
  CA_FILE="$CERT_TMP"
else
  [[ -f "$CA_FILE" && ! -L "$CA_FILE" ]] || { echo "ERROR: trusted CA file required" >&2; exit 1; }
fi
# Fetch agent code before consuming the one-time enrollment token.
# The release ref is an immutable commit, not a moving branch.
AGENT_RELEASE="166f287e5d8bea4a885b4b9d55509b6848c5f5f7"
AGENT_BASE="https://raw.githubusercontent.com/Cowprod/devMcp/$AGENT_RELEASE"
STAGING="$(mktemp -d)"
trap 'rm -rf "$STAGING"; if [[ -n "${CERT_TMP:-}" ]]; then rm -f "$CERT_TMP"; fi' EXIT
curl --proto '=https' --tlsv1.2 --fail --silent --show-error --location --max-time 30 \
  --output "$STAGING/distributed-agent.php" "$AGENT_BASE/bin/distributed-agent.php"
curl --proto '=https' --tlsv1.2 --fail --silent --show-error --location --max-time 30 \
  --output "$STAGING/devmcp-agent.service" "$AGENT_BASE/deploy/distributed/devmcp-agent.service"
curl --proto '=https' --tlsv1.2 --fail --silent --show-error --location --max-time 30 \
  --output "$STAGING/devmcp-agent.timer" "$AGENT_BASE/deploy/distributed/devmcp-agent.timer"
php -l "$STAGING/distributed-agent.php" >/dev/null
grep -Fqx 'ExecStart=/usr/bin/php /opt/devmcp-agent/bin/distributed-agent.php' "$STAGING/devmcp-agent.service" || {
  echo "ERROR: invalid agent service artifact" >&2; exit 1;
}
[[ -x /usr/bin/php ]] || { echo "ERROR: /usr/bin/php unavailable" >&2; exit 1; }
grep -Fqx 'Unit=devmcp-agent.service' "$STAGING/devmcp-agent.timer" || {
  echo "ERROR: invalid agent timer artifact" >&2; exit 1;
}
[[ ! -e /opt/devmcp-agent/bin/distributed-agent.php && ! -e /etc/systemd/system/devmcp-agent.service && ! -e /etc/systemd/system/devmcp-agent.timer ]] || {
  echo "ERROR: existing agent deployment; refusing overwrite" >&2; exit 1;
}
[[ ! -e /etc/devmcp/agent.php && ! -e /etc/devmcp/agent-token ]] || {
  echo "ERROR: agent identity already exists; refusing overwrite" >&2; exit 1;
}
command -v openssl >/dev/null || { echo "ERROR: openssl required" >&2; exit 1; }
command -v python3 >/dev/null || { echo "ERROR: python3 required" >&2; exit 1; }
umask 077
TMPDIR_AGENT="$STAGING/enrollment"
mkdir -m 0700 "$TMPDIR_AGENT"

printf '{"token":"%s"}' "$TOKEN" > "$TMPDIR_AGENT/request.json"
unset TOKEN
HTTP_CODE="$(curl --proto '=https' --silent --show-error --output "$TMPDIR_AGENT/response.json" \
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
if ! getent passwd devmcp-agent >/dev/null; then
  useradd --system --home-dir /var/lib/devmcp-agent --shell /usr/sbin/nologin --user-group devmcp-agent
fi
install -d -m 0750 -o root -g devmcp-agent /etc/devmcp
install -d -m 0700 -o devmcp-agent -g devmcp-agent /var/lib/devmcp-agent
install -m 0640 -o root -g devmcp-agent "$CA_FILE" /etc/devmcp/central-ca.pem
machine_id="$(sed -n '1p' "$TMPDIR_AGENT/identity")"
sed -n '2p' "$TMPDIR_AGENT/identity" > /etc/devmcp/agent-token
chown root:devmcp-agent /etc/devmcp/agent-token
chmod 0640 /etc/devmcp/agent-token
printf "<?php\nreturn ['machine_id' => '%s', 'central_url' => '%s', 'policy_file' => '/etc/devmcp/agent-policy.php', 'credential_file' => '/etc/devmcp/agent-token', 'ca_file' => '/etc/devmcp/central-ca.pem', 'state_dir' => '/var/lib/devmcp-agent'];\n" "$machine_id" "$SERVER" > /etc/devmcp/agent.php
chown root:devmcp-agent /etc/devmcp/agent.php
chmod 0640 /etc/devmcp/agent.php
install -d -m 0755 -o root -g root /opt/devmcp-agent/bin
install -m 0755 -o root -g root "$STAGING/distributed-agent.php" /opt/devmcp-agent/bin/distributed-agent.php
install -m 0644 -o root -g root "$STAGING/devmcp-agent.service" /etc/systemd/system/devmcp-agent.service
install -m 0644 -o root -g root "$STAGING/devmcp-agent.timer" /etc/systemd/system/devmcp-agent.timer
systemctl daemon-reload
systemctl enable --now devmcp-agent.timer
systemctl start devmcp-agent.service
echo "Installed and enrolled: machine $machine_id; devmcp-agent.timer enabled (remote execution disabled until explicitly approved)."
systemctl is-enabled --quiet devmcp-agent.timer || { echo "ERROR: agent timer not enabled" >&2; exit 1; }
systemctl --no-pager --full status devmcp-agent.timer || true

