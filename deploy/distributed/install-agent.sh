#!/usr/bin/env bash
# devMcp agent bootstrap preflight. Does not enroll or start an agent.
set -euo pipefail

usage() {
  echo "Usage: sudo bash install-agent.sh --server https://ZT_IP [--check-only]" >&2
  exit 2
}
SERVER=""
CHECK_ONLY=0
while (($#)); do
  case "$1" in
    --server) (($# >= 2)) || usage; SERVER="$2"; shift 2 ;;
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
echo "STOP: enrollment is not implemented yet; no files or services were changed." >&2
exit 3
