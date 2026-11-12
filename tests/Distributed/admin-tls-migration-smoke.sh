#!/usr/bin/env bash
set -euo pipefail
[[ "$EUID" == 0 && "${DEVMCP_RUN_ROOT_TESTS:-}" == 1 ]] || exit 2
for path in /etc/devmcp-admin /etc/nginx/sites-available/devmcp-admin.conf; do
  [[ ! -e "$path" && ! -L "$path" ]] || { echo "Fixture path occupied: $path" >&2; exit 1; }
done
cleanup() {
  rm -f /etc/nginx/sites-enabled/devmcp-tls-test /etc/nginx/sites-available/devmcp-admin.conf
  rm -rf /etc/devmcp-admin
  nginx -t && systemctl reload nginx
}
trap cleanup EXIT
install -d -m0750 -o root -g www-data /etc/devmcp-admin/tls
openssl req -x509 -newkey rsa:2048 -nodes -days 365 -subj /CN=127.0.0.1 \
  -addext 'basicConstraints=critical,CA:TRUE' -addext 'subjectAltName=IP:127.0.0.1' \
  -keyout /etc/devmcp-admin/tls/admin.key -out /etc/devmcp-admin/tls/admin.crt 2>/dev/null
cat > /etc/nginx/sites-available/devmcp-admin.conf <<'NGINX'
server {
    listen 127.0.0.1:443 ssl;
    server_name 127.0.0.1;
    ssl_certificate /etc/devmcp-admin/tls/admin.crt;
    ssl_certificate_key /etc/devmcp-admin/tls/admin.key;
    location = /devmcp-ca.crt { alias /etc/devmcp-admin/tls/admin.crt; }
    location / { return 204; }
}
NGINX
ln -s /etc/nginx/sites-available/devmcp-admin.conf /etc/nginx/sites-enabled/devmcp-tls-test
nginx -t
systemctl restart nginx
ca_before="$(sha256sum /etc/devmcp-admin/tls/admin.crt)"
key_before="$(sha256sum /etc/devmcp-admin/tls/admin.key)"
php bin/migrate-admin-tls.php --execute MIGRATE_ADMIN_TLS
[[ "$(sha256sum /etc/devmcp-admin/tls/admin.crt)" == "$ca_before" ]]
[[ "$(sha256sum /etc/devmcp-admin/tls/admin.key)" == "$key_before" ]]
openssl verify -purpose sslserver -verify_ip 127.0.0.1 -CAfile /etc/devmcp-admin/tls/admin.crt /etc/devmcp-admin/tls/admin-server.crt
curl --fail --silent --show-error --cacert /etc/devmcp-admin/tls/admin.crt https://127.0.0.1/ -o /dev/null
curl --fail --silent --show-error --cacert /etc/devmcp-admin/tls/admin.crt https://127.0.0.1/devmcp-ca.crt | cmp - /etc/devmcp-admin/tls/admin.crt
leaf_before="$(sha256sum /etc/devmcp-admin/tls/admin-server.crt)"
php bin/migrate-admin-tls.php --execute MIGRATE_ADMIN_TLS
[[ "$(sha256sum /etc/devmcp-admin/tls/admin-server.crt)" == "$leaf_before" ]]
[[ "$(stat -c %a /etc/devmcp-admin/tls/admin-server.key)" == 600 ]]
echo 'PASS: real nginx TLS migration, immutable CA download/pin, old agent trust and idempotent rerun'
