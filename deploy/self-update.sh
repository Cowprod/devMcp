#!/bin/sh
# Root-only VM updater. Never edits /etc/devmcp/global.php.
set -eu
[ "$(id -u)" -eq 0 ] || { echo "Run as root" >&2; exit 1; }
exec 9>/run/lock/devmcp-update.lock
flock -n 9 || { echo "devMcp update already running" >&2; exit 1; }
# Expose a non-secret diagnostic status to the unprivileged MCP requester.
# /run is root-controlled. The content is deliberately limited to fixed stages.
update_stage=preflight
status_file=/run/devmcp-update-status
trap 'rc=$?; umask 022; { printf "exit_code=%s\\nstage=%s\\n" "$rc" "$update_stage"; date -u "+finished_utc=%Y-%m-%dT%H:%M:%SZ"; } > "$status_file"' 0
# Clear any pending MCP request after acquiring the exclusive update lock.
rm -f /var/lib/devmcp/update-request
cd /opt/devmcp
# Git runs as root while the checkout belongs to the deployment user.
# Scope trust to this invocation; do not modify root's global git config.
export GIT_CONFIG_COUNT=1
export GIT_CONFIG_KEY_0=safe.directory
export GIT_CONFIG_VALUE_0=/opt/devmcp
[ "$(git rev-parse --show-toplevel)" = /opt/devmcp ] || exit 1
[ "$(git branch --show-current)" = main ] || { echo "Expected main branch" >&2; exit 1; }
[ -z "$(git status --porcelain --untracked-files=no)" ] || { echo "Tracked local modifications: abort" >&2; exit 1; }
update_stage=git-fetch
git fetch --no-tags origin main
old=$(git rev-parse HEAD)
new=$(git rev-parse refs/remotes/origin/main)
if [ "$old" = "$new" ]; then
    echo "devMcp core already up to date: $old"
else
git merge-base --is-ancestor "$old" "$new" || { echo "Non fast-forward update: abort" >&2; exit 1; }
git diff --name-only "$old" "$new" | grep -Eq '^(config/|src/|bin/|public/|vendor/|composer\.(json|lock)|deploy/)' || echo "Documentation-only update"
git merge --ff-only "$new"
fi
if [ -f /opt/devmcp/bin/devmcp-touchdeck-ota ]; then
    /bin/sh /opt/devmcp/deploy/install-touchdeck-ota.sh
fi
/usr/bin/php -l /etc/devmcp/global.php
/usr/bin/php -l /opt/devmcp/config/shared-actions.php
update_stage=dependencies
if [ -f composer.lock ]; then
    composer install --no-interaction --no-dev --prefer-dist --optimize-autoloader
fi
# One-time SQL import/cutover runs before any runtime restart. The importer
# is idempotent, retains the old PHP config as rollback reference and fails
# closed when a required SQL permission or action template is missing.
update_stage=sql-import
sql_import_output=$(/usr/bin/php /opt/devmcp/bin/import-global-projects-to-sql.php --execute MIGRATE_PROJECTS_TO_SQL)
printf '%s\n' "$sql_import_output"
sql_cutover=0
case "$sql_import_output" in
    *"SQL registry READY"*) sql_cutover=1 ;;
esac
update_stage=doctor
DEVMCP_CONFIG=/etc/devmcp/global.php /usr/bin/php /opt/devmcp/bin/devmcp-doctor
# Remember the last successfully reloaded revision independently of the Git
# checkout. If a previous run updated files but failed before reload, retry.
# /run is root-controlled and resets harmlessly at boot.
update_stage=restart
deployed_revision_file=/run/devmcp-runtime-revision
deployed_revision=
if [ -f "$deployed_revision_file" ]; then
    deployed_revision=$(cat "$deployed_revision_file")
fi
if [ "$old" != "$new" ] || [ "$sql_cutover" -eq 1 ] || [ "$deployed_revision" != "$new" ]; then
    systemctl try-restart devmcp-worker.service devmcp-tunnel.service devmcp-pm2.service
    printf '%s\n' "$new" > "$deployed_revision_file"
fi
# The web admin has a separate checkout; sync it on every scheduled run,
# including when the core is already current. The bridge deployer validates
# origins, clean worktrees, ancestry, PHP syntax and service permissions.
update_stage=admin-bridge
if [ -d /opt/devmcp-admin/.git ]; then
    /bin/bash /opt/devmcp/deploy/distributed/apply-local-admin-bridge.sh
fi
update_stage=complete
echo "devMcp update check completed: $old -> $new"
