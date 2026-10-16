#!/bin/sh
# Root-only VM updater. Never edits /etc/devmcp/global.php.
set -eu
[ "$(id -u)" -eq 0 ] || { echo "Run as root" >&2; exit 1; }
exec 9>/run/lock/devmcp-update.lock
flock -n 9 || { echo "devMcp update already running" >&2; exit 1; }
# Clear any pending MCP request after acquiring the exclusive update lock.\nrm -f /var/lib/devmcp/update-request\ncd /opt/devmcp\n# Git runs as root while the checkout belongs to the deployment user.
# Scope trust to this invocation; do not modify root's global git config.
export GIT_CONFIG_COUNT=1
export GIT_CONFIG_KEY_0=safe.directory
export GIT_CONFIG_VALUE_0=/opt/devmcp
[ "$(git rev-parse --show-toplevel)" = /opt/devmcp ] || exit 1
[ "$(git branch --show-current)" = main ] || { echo "Expected main branch" >&2; exit 1; }
[ -z "$(git status --porcelain --untracked-files=no)" ] || { echo "Tracked local modifications: abort" >&2; exit 1; }
git fetch --no-tags origin main
old=$(git rev-parse HEAD)
new=$(git rev-parse refs/remotes/origin/main)
[ "$old" = "$new" ] && { echo "devMcp already up to date: $old"; exit 0; }
git merge-base --is-ancestor "$old" "$new" || { echo "Non fast-forward update: abort" >&2; exit 1; }
git diff --name-only "$old" "$new" | grep -Eq '^(config/|src/|bin/|public/|vendor/|composer\.(json|lock)|deploy/)' || echo "Documentation-only update"
git merge --ff-only "$new"
if [ -f /opt/devmcp/bin/devmcp-touchdeck-ota ]; then
    /bin/sh /opt/devmcp/deploy/install-touchdeck-ota.sh
fi
/usr/bin/php -l /etc/devmcp/global.php
/usr/bin/php -l /opt/devmcp/config/shared-actions.php
if [ -f composer.lock ]; then
    composer install --no-interaction --no-dev --prefer-dist --optimize-autoloader
fi
DEVMCP_CONFIG=/etc/devmcp/global.php /usr/bin/php /opt/devmcp/bin/devmcp-doctor
systemctl try-restart devmcp-worker.service devmcp-tunnel.service devmcp-pm2.service
echo "devMcp updated: $old -> $new"
