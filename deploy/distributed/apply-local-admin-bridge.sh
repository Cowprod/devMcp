#!/usr/bin/env bash
# One-time coordinated deployment of the web admin and local read-only bridge.
# Never alters /etc/devmcp or /etc/devmcp-admin private configuration.
set -euo pipefail

[[ "$(id -u)" -eq 0 ]] || { echo "Root requis" >&2; exit 1; }

export GIT_CONFIG_COUNT=2
export GIT_CONFIG_KEY_0=safe.directory
export GIT_CONFIG_VALUE_0=/opt/devmcp
export GIT_CONFIG_KEY_1=safe.directory
export GIT_CONFIG_VALUE_1=/opt/devmcp-admin

for checkout in /opt/devmcp /opt/devmcp-admin; do
    [[ -d "$checkout/.git" ]] || { echo "Checkout Git absent : $checkout" >&2; exit 1; }
    [[ "$(git -C "$checkout" rev-parse --show-toplevel)" == "$checkout" ]] \
        || { echo "Mauvaise racine Git : $checkout" >&2; exit 1; }
    [[ "$(git -C "$checkout" branch --show-current)" == "main" ]] \
        || { echo "Branche non main : $checkout" >&2; exit 1; }
    [[ -z "$(git -C "$checkout" status --porcelain --untracked-files=no)" ]] \
        || { echo "Modifications suivies présentes : $checkout" >&2; exit 1; }
    remote="$(git -C "$checkout" remote get-url origin)"
    case "$remote" in
        https://github.com/Cowprod/devMcp|https://github.com/Cowprod/devMcp.git|git@github.com:Cowprod/devMcp|git@github.com:Cowprod/devMcp.git) ;;
        *) echo "Dépôt inattendu : $checkout" >&2; exit 1;;
    esac
    git -C "$checkout" fetch --no-tags origin main
    git -C "$checkout" merge-base --is-ancestor HEAD refs/remotes/origin/main \
        || { echo "Avance rapide impossible : $checkout" >&2; exit 1; }
    echo "Préflight OK: $checkout"
done

for checkout in /opt/devmcp /opt/devmcp-admin; do
    git -C "$checkout" merge --ff-only refs/remotes/origin/main
done

[[ -f /opt/devmcp-admin/src/Distributed/Admin/LocalProjectSnapshot.php ]] \
    || { echo "Version de l'admin sans le catalogue local" >&2; exit 1; }
/usr/bin/php -l /opt/devmcp-admin/public/distributed-admin.php
/usr/bin/php -l /opt/devmcp-admin/src/Distributed/Admin/AndroidSnapshot.php
/usr/bin/php -l /opt/devmcp/bin/devmcp-admin-android
bash /opt/devmcp/deploy/distributed/install-local-admin-bridge.sh

if systemctl is-active --quiet php8.3-fpm.service; then
    systemctl reload php8.3-fpm.service
fi
echo "PASS - Code des deux installations synchronisé; admin web et ADB actualisés."
