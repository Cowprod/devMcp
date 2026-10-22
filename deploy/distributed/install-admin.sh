#!/usr/bin/env bash
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Run as root" >&2
  exit 1
fi
: "${DEVMCP_ADMIN_ZT_IP:?Set DEVMCP_ADMIN_ZT_IP}"
: "${DEVMCP_ADMIN_PASSWORD:?Set DEVMCP_ADMIN_PASSWORD}"
: "${DEVMCP_ADMIN_DB_PASSWORD:?Set DEVMCP_ADMIN_DB_PASSWORD}"
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
install -d -m 0750 /etc/devmcp-admin
install -d -m 0750 /etc/devmcp-admin/tls
printf '%s\n' "$DEVMCP_ADMIN_PASSWORD" | htpasswd -iBc /etc/devmcp-admin/htpasswd admin
chmod 0640 /etc/devmcp-admin/htpasswd
if [ ! -f /etc/devmcp-admin/tls/admin.crt ]; then
  openssl req -x509 -newkey rsa:3072 -nodes -days 365 \
    -keyout /etc/devmcp-admin/tls/admin.key \
    -out /etc/devmcp-admin/tls/admin.crt \
    -subj "/CN=$DEVMCP_ADMIN_ZT_IP" \
    -addext "subjectAltName=IP:$DEVMCP_ADMIN_ZT_IP"
fi
chmod 0600 /etc/devmcp-admin/tls/admin.key
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
nginx -t
systemctl reload nginx
echo "HTTPS vhost configured. Database credentials and migrations must be configured separately."
