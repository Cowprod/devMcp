#!/usr/bin/env bash
# Root-approved change to the TouchDeck central HTTPS vhost ONLY.
# No router, WAN/LAN, routes, NAT, firewall, agent policy or app units touched.
set -euo pipefail
umask 077

[[ "$(id -u)" == 0 && "$#" == 1 && "$1" == "--root-approved" ]] || {
  echo 'Usage (central TouchDeck root only): enable-hosting-central-routes.sh --root-approved' >&2
  exit 2
}
conf=/etc/nginx/sites-available/devmcp-admin.conf
config=/etc/devmcp-admin/distributed.php
renderer="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)/render-hosting-central-routes.php"
routes="$(dirname "$renderer")/nginx-hosting-routes.conf"

# Do not follow symlinks, modify an unknown vhost, or change a foreign file.
[[ -f "$conf" && ! -L "$conf" && "$(stat -c '%u:%g:%a:%h' "$conf")" == '0:0:644:1'
  && -f "$config" && ! -L "$config" && "$(stat -c %u "$config")" == 0
  && -f "$renderer" && ! -L "$renderer" && -f "$routes" && ! -L "$routes" ]] || {
  echo 'Central vhost, configuration or route installer not trusted' >&2
  exit 1
}
# The SQL enrollment / grants and independent agent opt-in are not manufactured
# by this installer. Confirm the operator-enabled central flag before exposure.
php -r '
$c = require $argv[1];
exit(is_array($c) && ($c["remote_hosting_queue_enabled"] ?? null) === true
    && !empty($c["writer_username"]) && !empty($c["writer_password"]) ? 0 : 1);
' "$config" || {
  echo 'Central remote-hosting opt-in or writer credentials absent' >&2
  exit 1
}
for handler in agent-hosting-poll.php agent-hosting-result.php; do
  script="/opt/devmcp-admin/public/$handler"
  [[ -f "$script" && ! -L "$script" ]] || {
    echo "Central hosting PHP handler absent: $handler" >&2
    exit 1
  }
  php -l "$script" >/dev/null
done
[[ -S /run/php/php8.3-fpm.sock ]] || {
  echo 'Central PHP 8.3 FPM socket missing' >&2
  exit 1
}
command -v nginx >/dev/null
nginx -t >/dev/null

# The active vhost MUST bind to an IPv4 physically present on a zt* interface.
# No new listen directives are introduced by the renderer.
listen_ip="$(sed -nE 's/^[[:space:]]*listen[[:space:]]+([0-9.]+):443 ssl;[[:space:]]*$/\1/p' "$conf")"
[[ "$listen_ip" =~ ^[0-9]+(\.[0-9]+){3}$ ]] || {
  echo 'ZeroTier-only HTTPS listener not established' >&2
  exit 1
}
ip -o -4 addr show | awk -v want="$listen_ip" '
  $2 ~ /^zt[a-zA-Z0-9]+$/ {
    split($4, addr, "/");
    if (addr[1] == want) found = 1;
  }
  END { exit(found ? 0 : 1); }
' || {
  echo 'Central HTTPS listener is not on a ZeroTier interface' >&2
  exit 1
}

stage="$(mktemp /etc/nginx/sites-available/.devmcp-hosting-next.XXXXXXXX)"
backup="$(mktemp /etc/nginx/sites-available/.devmcp-hosting-old.XXXXXXXX)"
changed=0
committed=0
cleanup() {
  rc=$?
  trap - EXIT
  if [[ "$changed" -eq 1 && "$committed" -eq 0 ]]; then
    # Replace the whole file atomically; never leave a partly written vhost.
    mv -f -- "$backup" "$conf" || true
    nginx -t >/dev/null 2>&1 && systemctl reload nginx || true
  fi
  rm -f -- "$stage" "$backup"
  exit "$rc"
}
trap cleanup EXIT

php "$renderer" "$conf" > "$stage"
if cmp -s -- "$stage" "$conf"; then
  echo 'PASS: both central hosting HTTPS routes already provisioned (no reload)'
  exit 0
fi
cp -p -- "$conf" "$backup"
chown root:root "$stage"
chmod 0644 "$stage"
mv -f -- "$stage" "$conf"
changed=1
nginx -t
systemctl reload nginx
committed=1
echo 'PASS: central hosting HTTPS poll/result routes installed; no application deployed'
