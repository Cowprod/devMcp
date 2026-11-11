#!/usr/bin/env bash
# Disposable root CI fixture for the actual nginx migration and credential reset.
set -euo pipefail
[[ "$EUID" == 0 && "${DEVMCP_RUN_ROOT_TESTS:-}" == 1 ]] || exit 2
for path in /etc/devmcp-admin /var/lib/devmcp-admin-auth /run/devmcp-admin-bootstrap-password /etc/nginx/sites-available/devmcp-admin.conf; do
  [[ ! -e "$path" && ! -L "$path" ]] || { echo "Fixture path occupied: $path" >&2;exit 1; }
done
created_group=0
if ! getent group devmcp >/dev/null; then groupadd --system devmcp;created_group=1;fi
cleanup() {
  rm -f /etc/nginx/sites-enabled/devmcp-auth-test /etc/nginx/sites-available/devmcp-admin.conf /run/devmcp-admin-bootstrap-password
  rm -rf /etc/devmcp-admin /var/lib/devmcp-admin-auth
  nginx -t && systemctl reload nginx
  if [[ "$created_group" == 1 ]]; then groupdel devmcp;fi
}
trap cleanup EXIT
install -d -m0750 -o root -g www-data /etc/devmcp-admin
printf '%s\n' 'admin:old-credential-preserved-in-backup' > /etc/devmcp-admin/htpasswd
cat > /etc/nginx/sites-available/devmcp-admin.conf <<'NGINX'
server {
    listen 127.0.0.1:18443;
    server_name localhost;
    auth_basic "devMcp administration";
    auth_basic_user_file /etc/devmcp-admin/htpasswd;
    location / { return 204; }
}
NGINX
ln -s /etc/nginx/sites-available/devmcp-admin.conf /etc/nginx/sites-enabled/devmcp-auth-test
systemctl start nginx
php bin/migrate-admin-form-auth.php --execute MIGRATE_ADMIN_FORM_AUTH
php -r '
$c=require "/etc/devmcp-admin/web-auth.php";
$s=json_decode(file_get_contents("/run/devmcp-admin-bootstrap-password"),true,8,JSON_THROW_ON_ERROR);
if($s["username"]!=="admin"||!password_verify($s["password"],$c["password_hash"]))throw new RuntimeException("Reset credential mismatch");
if((fileperms("/etc/devmcp-admin/web-auth.php")&0777)!==0640)throw new RuntimeException("Credential permissions mismatch");
if(str_contains(file_get_contents("/etc/devmcp-admin/web-auth.php"),$s["password"]))throw new RuntimeException("Plaintext persisted in credential store");
if(fileowner("/var/lib/devmcp-admin-auth")!==posix_getpwnam("www-data")["uid"])throw new RuntimeException("Session storage ownership mismatch");
'
before="$(sha256sum /etc/devmcp-admin/web-auth.php)"
php bin/migrate-admin-form-auth.php --execute MIGRATE_ADMIN_FORM_AUTH
[[ "$(sha256sum /etc/devmcp-admin/web-auth.php)" == "$before" ]]
grep -Fq 'auth_basic off;' /etc/nginx/sites-available/devmcp-admin.conf
[[ "$(cat /etc/devmcp-admin/htpasswd)" == 'admin:old-credential-preserved-in-backup' ]]
echo 'PASS: nginx HTML auth migration, generated password verification, permissions and idempotent rerun'
