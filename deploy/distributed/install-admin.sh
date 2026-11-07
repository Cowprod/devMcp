#!/usr/bin/env bash
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Run as root" >&2
  exit 1
fi
: "${DEVMCP_ADMIN_ZT_IP:?Set DEVMCP_ADMIN_ZT_IP}"
: "${DEVMCP_ADMIN_PASSWORD:?Set DEVMCP_ADMIN_PASSWORD}"
SOURCE="${DEVMCP_ADMIN_SOURCE:-/opt/devmcp-admin}"
if [ ! -f "$SOURCE/public/distributed-admin.php" ] || [ ! -f "$SOURCE/vendor/autoload.php" ]; then
  echo "Application and Composer dependencies must be installed first" >&2
  exit 1
fi
if ! ip -4 addr show | grep -Fq "inet $DEVMCP_ADMIN_ZT_IP/"; then
  echo "ZeroTier address is not assigned locally" >&2
  exit 1
fi
command -v htpasswd >/dev/null || { echo "Install apache2-utils" >&2; exit 1; }
command -v openssl >/dev/null || exit 1
install -d -m 0750 -o root -g www-data /etc/devmcp-admin
install -d -m 0750 -o root -g www-data /etc/devmcp-admin/tls
printf '%s\n' "$DEVMCP_ADMIN_PASSWORD" | htpasswd -iBc /etc/devmcp-admin/htpasswd admin
chown root:www-data /etc/devmcp-admin/htpasswd
chmod 0640 /etc/devmcp-admin/htpasswd
if [ ! -f /etc/devmcp-admin/tls/admin.crt ]; then
  openssl req -x509 -newkey rsa:3072 -nodes -days 365 \
    -keyout /etc/devmcp-admin/tls/admin.key \
    -out /etc/devmcp-admin/tls/admin.crt \
    -subj "/CN=$DEVMCP_ADMIN_ZT_IP" \
    -addext "subjectAltName=IP:$DEVMCP_ADMIN_ZT_IP"
fi
chown root:www-data /etc/devmcp-admin/tls/admin.key
chmod 0640 /etc/devmcp-admin/tls/admin.key
chmod 0644 /etc/devmcp-admin/tls/admin.crt
SOCKET=/run/php/php8.3-fpm.sock
test -S "$SOCKET"
sed -e "s|__ZT_IP__|$DEVMCP_ADMIN_ZT_IP|g" \
    -e "s|__CERT__|/etc/devmcp-admin/tls/admin.crt|g" \
    -e "s|__KEY__|/etc/devmcp-admin/tls/admin.key|g" \
    -e "s|__HTPASSWD__|/etc/devmcp-admin/htpasswd|g" \
    -e "s|__FPM_SOCKET__|$SOCKET|g" \
    "$SOURCE/deploy/distributed/nginx-admin.conf.example" > /etc/nginx/sites-available/devmcp-admin.conf
ln -sfn /etc/nginx/sites-available/devmcp-admin.conf /etc/nginx/sites-enabled/devmcp-admin.conf
# Keep the HTTPS bind strictly on ZeroTier. On cold boots the private IP may
# not be assigned when nginx first starts; systemd must retry without any
# fallback wildcard listener. Preserve the operator's existing override.
RETRY_TEMPLATE="$SOURCE/deploy/distributed/nginx-zerotier-retry.conf"
RETRY_DIR=/etc/systemd/system/nginx.service.d
RETRY_DEST="$RETRY_DIR/restart.conf"
[[ -f "$RETRY_TEMPLATE" && ! -L "$RETRY_TEMPLATE" ]] || { echo "Missing trusted nginx retry template" >&2; exit 1; }
install -d -m 0755 -o root -g root "$RETRY_DIR"
if [[ -e "$RETRY_DEST" || -L "$RETRY_DEST" ]]; then
  [[ -f "$RETRY_DEST" && ! -L "$RETRY_DEST" ]] && cmp -s -- "$RETRY_TEMPLATE" "$RETRY_DEST" || {
    echo "Existing nginx restart override differs; refusing to overwrite" >&2
    exit 1
  }
else
  install -m 0644 -o root -g root "$RETRY_TEMPLATE" "$RETRY_DEST"
fi
systemctl daemon-reload
nginx -t
systemctl reload nginx
echo "HTTPS vhost configured with persistent ZeroTier bind retry. Database credentials and migrations must be configured separately."
