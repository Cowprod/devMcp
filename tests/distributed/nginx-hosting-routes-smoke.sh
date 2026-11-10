#!/usr/bin/env bash
# Pure renderer regression: no root, real nginx or server is required.
set -euo pipefail
root="$(cd "$(dirname "$0")/../.." && pwd -P)"
render="$root/deploy/distributed/render-hosting-central-routes.php"
installer="$root/deploy/distributed/enable-hosting-central-routes.sh"
routes="$root/deploy/distributed/nginx-hosting-routes.conf"
php -l "$render" >/dev/null
bash -n "$installer"
test -s "$routes"

scratch="$(mktemp -d)"
trap 'rm -rf -- "$scratch"' EXIT
cat > "$scratch/vhost" <<'NGINX'
server {
    listen 192.168.191.9:443 ssl;
    server_name 192.168.191.9;
    root /opt/devmcp-admin/public;
    location = /v1/agent/ping {
        return 204;
    }
    location = /devmcp-ca.crt {
        return 200;
    }
    location / {
        return 404;
    }
}
NGINX
php "$render" "$scratch/vhost" > "$scratch/installed"
test "$(grep -c '^[[:space:]]*location = /v1/agent/hosting-' "$scratch/installed")" -eq 2
grep -Fq 'fastcgi_param SCRIPT_FILENAME /opt/devmcp-admin/public/agent-hosting-poll.php;' "$scratch/installed"
grep -Fq 'fastcgi_param SCRIPT_FILENAME /opt/devmcp-admin/public/agent-hosting-result.php;' "$scratch/installed"
grep -Fq 'auth_basic off;' "$scratch/installed"
grep -Fq 'limit_except POST { deny all; }' "$scratch/installed"
php "$render" "$scratch/installed" > "$scratch/again"
cmp -s "$scratch/installed" "$scratch/again"

# Fail closed on wildcard or wrong listener, missing bearer-enabled ping,
# unknown existing location, and injected second virtual host.
sed 's/192.168.191.9:443 ssl/0.0.0.0:443 ssl/' "$scratch/vhost" > "$scratch/bad"
if php "$render" "$scratch/bad" > /dev/null 2>&1; then echo 'Wildcard accepted' >&2; exit 1; fi
sed '/location = \/v1\/agent\/ping/,+2d' "$scratch/vhost" > "$scratch/bad"
if php "$render" "$scratch/bad" > /dev/null 2>&1; then echo 'Missing ping accepted' >&2; exit 1; fi
sed '/location = \/devmcp-ca.crt {/i\    location = /v1/agent/hosting-poll {' "$scratch/vhost" > "$scratch/bad"
if php "$render" "$scratch/bad" > /dev/null 2>&1; then echo 'Conflicting route accepted' >&2; exit 1; fi
cat "$scratch/vhost" "$scratch/vhost" > "$scratch/bad"
if php "$render" "$scratch/bad" > /dev/null 2>&1; then echo 'Multiple vhosts accepted' >&2; exit 1; fi

grep -Fq 'remote_hosting_queue_enabled' "$installer"
grep -Fq 'nginx -t' "$installer"
grep -Fq 'systemctl reload nginx' "$installer"
grep -Fq 'ip -o -4 addr show' "$installer"
# The central privileged installer must not allow two overlapping writes or
# silently discard a concurrent manual Nginx configuration edit.
grep -Fq 'flock -n 9' "$installer"
grep -Fq 'Untrusted central lock file' "$installer"
grep -Fq 'cmp -s -- "$backup" "$conf"' "$installer"
# Before the root installer requires PHP config, the *full* config path must
# pass the same ownership, write-bit, hardlink and size restrictions as the
# central root-only batch tools. Checking only the UID is insufficient.
grep -Fq 'for trusted_dir in /etc /etc/devmcp-admin' "$installer"
grep -Fq '8#$dir_mode & 0022' "$installer"
grep -Fq '8#$config_mode & 0022' "$installer"
grep -Fq 'config_links" == 1' "$installer"
grep -Fq 'config_size" -le 65536' "$installer"
grep -Fq 'Untrusted central configuration file permissions' "$installer"
# The source checkout is writable: only the reviewed SHA-1 PHP/template
# bytes copied into a private root-owned snapshot may be executed as root.
grep -Fq 'git hash-object -- "$renderer"' "$installer"
grep -Fq 'git hash-object -- "$routes"' "$installer"
grep -Fq 'install -o root -g root -m 0600 "$renderer" "$snapshot/render-hosting-central-routes.php"' "$installer"
grep -Fq 'renderer="$snapshot/render-hosting-central-routes.php"' "$installer"
grep -Fq 'Central hosting snapshot integrity check failed' "$installer"
# The independent EXIT trap is overwritten by the vhost transaction cleanup.
# This cleanup must remove both the stage/backup and the private snapshot.
python3 - "$installer" <<'PY'
import pathlib, sys
source = pathlib.Path(sys.argv[1]).read_text()
expected = 'rm -f -- "$stage" "$backup"\n  rm -rf -- "$snapshot"'
if expected not in source:
    raise SystemExit('Private root snapshot is not cleaned up in the vhost EXIT trap')
PY
printf 'PASS: private hosting routes, idempotence, pinned root snapshot and installer syntax\n'
