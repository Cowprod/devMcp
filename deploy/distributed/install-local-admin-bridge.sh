#!/usr/bin/env bash
set -euo pipefail

[[ "$(id -u)" -eq 0 ]] || { echo "Root requis" >&2; exit 1; }
getent passwd devmcp >/dev/null || { echo "Utilisateur devmcp absent" >&2; exit 1; }
getent group www-data >/dev/null || { echo "Groupe www-data absent" >&2; exit 1; }
[[ -f /opt/devmcp/bin/devmcp-admin-projects-export ]] || { echo "Mise à jour /opt/devmcp requise" >&2; exit 1; }
[[ -f /opt/devmcp/bin/devmcp-admin-android ]] || { echo "Worker Android absent" >&2; exit 1; }
[[ -f /opt/devmcp-admin/src/Distributed/Admin/AndroidSnapshot.php ]] || { echo "Mise à jour /opt/devmcp-admin requise" >&2; exit 1; }
for path in /var/lib/devmcp/admin-share /var/lib/devmcp/admin-share/requests /var/lib/devmcp/admin-share/screenshots; do
    [[ ! -L "$path" ]] || { echo "Lien symbolique interdit : $path" >&2; exit 1; }
done

install -d -o devmcp -g www-data -m 2750 /var/lib/devmcp/admin-share
install -d -o devmcp -g www-data -m 2770 /var/lib/devmcp/admin-share/requests
install -d -o devmcp -g www-data -m 2750 /var/lib/devmcp/admin-share/screenshots

for file in devmcp-admin-projects.service devmcp-admin-projects.timer devmcp-admin-android.service devmcp-admin-android.timer; do
    install -o root -g root -m 0644 "/opt/devmcp/deploy/systemd/$file" "/etc/systemd/system/$file"
done
php -l /opt/devmcp/src/Distributed/Admin/LocalProjectSnapshot.php
php -l /opt/devmcp/src/Distributed/Admin/AndroidSnapshot.php
php -l /opt/devmcp-admin/public/distributed-admin.php
systemctl daemon-reload
systemctl enable --now devmcp-admin-projects.timer devmcp-admin-android.timer
systemctl start devmcp-admin-projects.service devmcp-admin-android.service
test -r /var/lib/devmcp/admin-share/projects.json
test -r /var/lib/devmcp/admin-share/android.json
echo "OK - Catalogue local + inventaire ADB publiés"
