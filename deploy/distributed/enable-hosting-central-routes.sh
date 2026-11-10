#!/usr/bin/env bash
# Root-approved change to the TouchDeck central HTTPS vhost ONLY.
# No router, WAN/LAN, routes, NAT, firewall, agent policy or app units touched.
set -euo pipefail
umask 077
# Never resolve privileged utilities or PHP from an inherited executable path.
# Pre-start BASH_ENV evaluation still requires caller-side environment reset.
export PATH=/usr/sbin:/usr/bin:/sbin:/bin
unset CDPATH ENV BASH_ENV PHPRC PHP_INI_SCAN_DIR LD_PRELOAD LD_LIBRARY_PATH LD_AUDIT

[[ "$(id -u)" == 0 && "$#" == 1 && "$1" == "--root-approved" ]] || {
  echo 'Usage (central TouchDeck root only): enable-hosting-central-routes.sh --root-approved' >&2
  exit 2
}
conf=/etc/nginx/sites-available/devmcp-admin.conf
config=/etc/devmcp-admin/distributed.php
renderer="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)/render-hosting-central-routes.php"
routes="$(dirname "$renderer")/nginx-hosting-routes.conf"

# Do not trust a copied installer from a different checkout (e.g. /tmp)
# merely because the canonical source tree happens to be protected.
[[ "$renderer" == '/opt/devmcp/deploy/distributed/render-hosting-central-routes.php' ]] || {
  echo 'Untrusted central hosting installer location' >&2
  exit 1
}

# The Git checkout is intentionally writable by the deployment account.
# Never execute PHP from that checkout as root. Pin the reviewed Git blobs,
# copy them to a private root-owned snapshot, and execute ONLY that snapshot.
# Revisions to the renderer/template require updating these reviewed pins.
for source_dir in /opt /opt/devmcp /opt/devmcp/deploy /opt/devmcp/deploy/distributed; do
  [[ -d "$source_dir" && ! -L "$source_dir" ]] || {
    echo 'Untrusted central hosting source directory' >&2; exit 1;
  }
done
[[ -f "$renderer" && ! -L "$renderer" && -f "$routes" && ! -L "$routes" ]] || {
  echo 'Missing or linked central hosting source' >&2; exit 1;
}
[[ "$(git hash-object -- "$renderer")" == '16dc5db499a4e702dd0003dab3e1d9ef2acc7536'
   && "$(git hash-object -- "$routes")" == '7a246b7130c65fd4cb749f003f6a099be125ea4a' ]] || {
  echo 'Central hosting source differs from reviewed revision' >&2; exit 1;
}
snapshot="$(mktemp -d /run/devmcp-hosting-render.XXXXXXXX)"
chmod 0700 "$snapshot"
trap 'rm -rf -- "$snapshot"' EXIT
install -o root -g root -m 0600 "$renderer" "$snapshot/render-hosting-central-routes.php"
install -o root -g root -m 0600 "$routes" "$snapshot/nginx-hosting-routes.conf"
[[ "$(git hash-object -- "$snapshot/render-hosting-central-routes.php")" == '16dc5db499a4e702dd0003dab3e1d9ef2acc7536'
   && "$(git hash-object -- "$snapshot/nginx-hosting-routes.conf")" == '7a246b7130c65fd4cb749f003f6a099be125ea4a' ]] || {
  echo 'Central hosting snapshot integrity check failed' >&2; exit 1;
}
renderer="$snapshot/render-hosting-central-routes.php"
routes="$snapshot/nginx-hosting-routes.conf"

# Serialize privileged vhost replacements. A permanent root-owned lock inode
# survives atomic conf renames; kernel flock is released on crash/exit.
# Never open an untrusted or symlinked lock path as root.
lock_dir=/run/lock
lock_path="$lock_dir/devmcp-hosting-central-nginx.lock"
[[ -d "$lock_dir" && ! -L "$lock_dir" && "$(stat -c '%u:%g' "$lock_dir")" == '0:0' ]] || {
  echo 'Untrusted central lock directory' >&2
  exit 1
}
if [[ ! -e "$lock_path" && ! -L "$lock_path" ]]; then
  ( set -C; : > "$lock_path" ) 2>/dev/null || {
    echo 'Unable to create exclusive central lock file' >&2
    exit 1
  }
fi
[[ -f "$lock_path" && ! -L "$lock_path"
   && "$(stat -c '%u:%g:%a:%h' "$lock_path")" == '0:0:600:1' ]] || {
  echo 'Untrusted central lock file' >&2
  exit 1
}
command -v flock >/dev/null
exec 9<>"$lock_path"
flock -n 9 || {
  echo 'Another central hosting route installation is in progress' >&2
  exit 1
}

# Do not follow symlinks, modify an unknown vhost, or change a foreign file.
[[ -f "$conf" && ! -L "$conf" && "$(stat -c '%u:%g:%a:%h' "$conf")" == '0:0:644:1'
  && -f "$config" && ! -L "$config"
  && -f "$renderer" && ! -L "$renderer" && -f "$routes" && ! -L "$routes" ]] || {
  echo 'Central vhost, configuration or route installer not trusted' >&2
  exit 1
}
# The central PHP configuration is executed as root by the opt-in check.
# Match the root-only lab CLIs: every parent must be root-owned and not
# group/other writable; the config must be a bounded, single-link regular
# file owned by root and not group/other writable. A symlink or writable
# config could otherwise execute attacker-controlled PHP with root rights.
for trusted_dir in /etc /etc/devmcp-admin; do
  [[ -d "$trusted_dir" && ! -L "$trusted_dir" ]] || {
    echo 'Untrusted central configuration directory' >&2
    exit 1
  }
  read -r dir_owner dir_mode < <(stat -c '%u %a' "$trusted_dir")
  [[ "$dir_owner" == 0 ]] && (( (8#$dir_mode & 0022) == 0 )) || {
    echo 'Untrusted central configuration directory' >&2
    exit 1
  }
done
read -r config_owner config_mode config_links config_size < <(stat -c '%u %a %h %s' "$config")
[[ "$config_owner" == 0 && "$config_links" == 1
  && "$config_size" -ge 1 && "$config_size" -le 65536 ]] \
  && (( (8#$config_mode & 0022) == 0 )) || {
    echo 'Untrusted central configuration file permissions' >&2
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
  rm -rf -- "$snapshot"
  exit "$rc"
}
trap cleanup EXIT

php "$renderer" "$conf" > "$stage"
if cmp -s -- "$stage" "$conf"; then
  echo 'PASS: both central hosting HTTPS routes already provisioned (no reload)'
  exit 0
fi
cp -p -- "$conf" "$backup"
# A concurrent administrator edit must not be silently overwritten, even
# though this installer holds its own lock. Operators editing Nginx manually
# will not necessarily cooperate with the advisory lock.
cmp -s -- "$backup" "$conf" || {
  echo 'Central Nginx vhost changed during preparation; refusing overwrite' >&2
  exit 1
}
chown root:root "$stage"
chmod 0644 "$stage"
mv -f -- "$stage" "$conf"
changed=1
nginx -t
systemctl reload nginx
committed=1
echo 'PASS: central hosting HTTPS poll/result routes installed; no application deployed'
