#!/usr/bin/env bash
# Static regression for the private ZeroTier nginx cold-boot retry contract.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd)"
config="$root/deploy/distributed/nginx-zerotier-retry.conf"
nginx="$root/deploy/distributed/nginx-admin.conf.example"
installer="$root/deploy/distributed/install-admin.sh"

# Keep exactly the bytes of the independently installed TouchDeck override:
# re-running install-admin.sh must not refuse an equivalent existing file.
diff -u "$config" - <<'EXPECTED'
[Unit]
StartLimitIntervalSec=0
[Service]
Restart=on-failure
RestartSec=10s
EXPECTED
grep -Fqx '    listen __ZT_IP__:443 ssl;' "$nginx"
if grep -Eq '^[[:space:]]*listen[[:space:]]+([*:]|0\.0\.0\.0|\[::\])' "$nginx"; then
  echo 'Wildcard nginx listener forbidden' >&2
  exit 1
fi
grep -Fq 'RETRY_DEST="$RETRY_DIR/restart.conf"' "$installer"
grep -Fq 'cmp -s -- "$RETRY_TEMPLATE" "$RETRY_DEST"' "$installer"
grep -Fq 'systemctl daemon-reload' "$installer"
bash -n "$installer"
printf 'PASS: ZeroTier-only nginx bind, systemd retry and installer idempotence contract\n'
