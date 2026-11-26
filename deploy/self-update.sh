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
deployed_revision_file=/run/devmcp-runtime-revision
# Scope trust to this invocation, including the initial revision lookup.
export GIT_CONFIG_COUNT=1
export GIT_CONFIG_KEY_0=safe.directory
export GIT_CONFIG_VALUE_0=/opt/devmcp
# Persist a per-request result outside /run, including the requested and installed SHA.
# The status directory is root-owned and readable by the MCP service.
checkout=/opt/devmcp
validator=$checkout/deploy/validate-update.sh
probe=$checkout/deploy/check-mcp.php
stage_root=
candidate=
rollback_armed=0
rollback_result=not-needed
vendor_replaced=0
restart_http=0
completed_stages=
advance() {
    completed_stages=${completed_stages:+$completed_stages,}\"$update_stage\"
}
tracking_dir=/var/lib/devmcp-update-status
mkdir -p "$tracking_dir"
chmod 755 "$tracking_dir"
request_id=
requested_revision=
if [ -f /var/lib/devmcp/update-request ] && [ ! -L /var/lib/devmcp/update-request ]; then
    read -r request_id requested_revision < /var/lib/devmcp/update-request || true
    rm -f /var/lib/devmcp/update-request
fi
case "$request_id" in
    *[!a-f0-9]*|'') request_id= ;;
esac
[ "${#request_id}" -eq 32 ] || request_id=
case "$requested_revision" in
    *[!a-f0-9]*|'') requested_revision= ;;
esac
[ "${#requested_revision}" -eq 40 ] || requested_revision=
started_utc=$(date -u '+%Y-%m-%dT%H:%M:%SZ')
installed_before=$(git -C /opt/devmcp rev-parse HEAD 2>/dev/null || true)
write_tracking_status() {
    rc=$1
    stage=$2
    installed_after=$(git -C /opt/devmcp rev-parse HEAD 2>/dev/null || true)
    runtime_revision=$(cat "$deployed_revision_file" 2>/dev/null || true)
    result=failed
    if [ "$rc" -eq 0 ] && [ "$stage" = complete ] && [ -n "$installed_after" ] &&
        [ "$runtime_revision" = "$installed_after" ] &&
        { [ -z "$request_id" ] || [ "$requested_revision" = "$installed_after" ]; }; then
        result=succeeded
    fi
    [ -n "$request_id" ] || request_id=untracked
    tmp=$(mktemp "$tracking_dir/.result.XXXXXX")
    printf '{"update_id":"%s","status":"%s","exit_code":%s,"stage":"%s","requested_revision":"%s","installed_before":"%s","installed_after":"%s","runtime_revision":"%s","started_utc":"%s","finished_utc":"%s","completed_stages":[%s],"rollback":"%s"}\n' \
        "$request_id" "$result" "$rc" "$stage" "$requested_revision" "$installed_before" "$installed_after" "$runtime_revision" "$started_utc" "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$completed_stages" "$rollback_result" > "$tmp"
    chmod 644 "$tmp"
    if [ "$request_id" != untracked ]; then
        ln "$tmp" "$tracking_dir/$request_id.json"
    fi
    mv -f "$tmp" "$tracking_dir/latest.json"
}
verify_runtime() {
    timeout 15 /usr/sbin/runuser -u devmcp -- /usr/bin/php "$probe" "$checkout" || return 1
    attempts=0
    while [ "$attempts" -lt 20 ]; do
        pid=$(systemctl show devmcp-tunnel.service -p MainPID --value) || return 1
        if [ "$pid" -gt 0 ] && systemctl is-active --quiet devmcp-tunnel.service &&
            timeout 4 /usr/local/bin/tunnel-client health --port 8080 --pid "$pid" --require-control-plane-poll >/dev/null 2>&1; then
            sleep 2
            [ "$pid" = "$(systemctl show devmcp-tunnel.service -p MainPID --value)" ] &&
                systemctl is-active --quiet devmcp-tunnel.service && return 0
        fi
        attempts=$((attempts + 1))
        sleep 2
    done
    echo 'Tunnel did not become healthy and stable.' >&2
    return 1
}
restart_runtime() {
    systemctl try-restart devmcp-worker.service devmcp-pm2.service || return 1
    systemctl restart devmcp-tunnel.service || return 1
    if [ "$restart_http" -eq 1 ]; then
        systemctl restart devmcp-http.service || return 1
    fi
}
rollback() {
    echo "Restoring devMcp revision $old after $update_stage failed." >&2
    git -C "$checkout" reset --keep "$old" || return 1
    if [ "$vendor_replaced" -eq 1 ]; then
        if [ -d "$checkout/vendor" ]; then
            mv "$checkout/vendor" "$stage_root/failed-vendor" || return 1
        fi
        mv "$stage_root/previous-vendor" "$checkout/vendor" || return 1
    fi
    restart_runtime && verify_runtime || return 1
    printf '%s\n' "$old" > "$deployed_revision_file"
}
finish() {
    rc=$1
    trap - 0 INT TERM
    if [ "$rc" -ne 0 ] && [ "$rollback_armed" -eq 1 ]; then
        if rollback; then
            rollback_result=succeeded
        else
            rollback_result=failed
            echo "Rollback failed; recovery files retained in $stage_root." >&2
        fi
    fi
    if [ -n "$stage_root" ] && [ "$rollback_result" != failed ]; then
        if [ -n "$candidate" ] && [ -e "$candidate/.git" ]; then
            git -C "$checkout" worktree remove --force "$candidate" || true
        fi
        rm -rf -- "$stage_root"
    fi
    umask 022
    {
        printf "exit_code=%s\nstage=%s\n" "$rc" "$update_stage"
        date -u "+finished_utc=%Y-%m-%dT%H:%M:%SZ"
    } > "$status_file"
    write_tracking_status "$rc" "$update_stage"
    exit "$rc"
}
trap 'finish $?' 0
trap 'exit 130' INT
trap 'exit 143' TERM
cd /opt/devmcp
[ "$(git rev-parse --show-toplevel)" = /opt/devmcp ] || exit 1
[ "$(git branch --show-current)" = main ] || { echo "Expected main branch" >&2; exit 1; }
[ -z "$(git status --porcelain --untracked-files=no)" ] || { echo "Tracked local modifications: abort" >&2; exit 1; }
advance
update_stage=git-fetch
git fetch --no-tags origin main
old=$(git rev-parse HEAD)
new=$(git rev-parse refs/remotes/origin/main)
if [ -n "$request_id" ] && [ "$requested_revision" != "$new" ]; then
    advance
    update_stage=revision-mismatch
    echo "Requested revision does not match current origin/main; queue a fresh request" >&2
    exit 1
fi
if [ -z "$requested_revision" ]; then
    requested_revision=$new
fi
if systemctl is-active --quiet devmcp-http.service; then
    restart_http=1
fi
if [ "$old" = "$new" ]; then
    echo "devMcp core already up to date: $old"
else
    git merge-base --is-ancestor "$old" "$new" || { echo "Non fast-forward update: abort" >&2; exit 1; }
    advance
    update_stage=stage-candidate
    stage_root=$(mktemp -d /opt/.devmcp-update.XXXXXXXX)
    chmod 755 "$stage_root"
    candidate=$stage_root/checkout
    git worktree add --quiet --detach "$candidate" "$new"
    cp "$probe" "$stage_root/check-mcp.php"
    probe=$stage_root/check-mcp.php

    advance
    update_stage=candidate-lint
    /bin/sh "$validator" lint "$candidate"
    /usr/bin/php -l /etc/devmcp/global.php

    advance
    update_stage=candidate-dependencies
    (cd "$candidate" && COMPOSER_HOME="$stage_root/composer" COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-plugins --no-scripts --prefer-dist)
    advance
    update_stage=candidate-tests
    /usr/sbin/runuser -u nobody -- /bin/sh "$validator" tests "$candidate"

    advance
    update_stage=previous-runtime
    verify_runtime
    git update-ref refs/devmcp/previous "$old"
    if ! git diff --quiet "$old" "$new" -- composer.json composer.lock; then
        advance
        update_stage=production-dependencies
        (cd "$candidate" && COMPOSER_HOME="$stage_root/composer" COMPOSER_ALLOW_SUPERUSER=1 composer install --no-interaction --no-dev --no-plugins --no-scripts --prefer-dist --optimize-autoloader)
        test -d "$checkout/vendor"
    fi

    advance
    update_stage=activate-candidate
    test -z "$(git status --porcelain --untracked-files=no)"
    rollback_armed=1
    git merge --ff-only "$new"
    if ! git diff --quiet "$old" "$new" -- composer.json composer.lock; then
        mv "$checkout/vendor" "$stage_root/previous-vendor"
        vendor_replaced=1
        mv "$candidate/vendor" "$checkout/vendor"
    fi
fi
# One-time SQL import/cutover runs before any runtime restart. The importer
# is idempotent, retains the old PHP config as rollback reference and fails
# closed when a required SQL permission or action template is missing.
advance
update_stage=sql-import
sql_import_output=$(/usr/bin/php /opt/devmcp/bin/import-global-projects-to-sql.php --execute MIGRATE_PROJECTS_TO_SQL)
printf '%s\n' "$sql_import_output"
sql_cutover=0
case "$sql_import_output" in
    *"SQL registry READY"*) sql_cutover=1 ;;
esac
# Publish the standard test action after SQL import, without changing legacy
# manual projects. This only configures a job action; no tests run on deploy.
# Register the restricted local service action before other action migrations.
# No application is started by registering an action.
advance
update_stage=local-android-action
/usr/bin/php /opt/devmcp/bin/migrate-local-android-action.php --execute MIGRATE_LOCAL_ANDROID
advance
update_stage=admin-update-action
/usr/bin/php /opt/devmcp/bin/migrate-admin-update.php --execute MIGRATE_ADMIN_UPDATE
advance
update_stage=android-diagnostics
/usr/bin/php /opt/devmcp/bin/migrate-android-diagnostics.php --execute MIGRATE_ANDROID_DIAGNOSTICS
advance
update_stage=project-service-action
/usr/bin/php /opt/devmcp/bin/migrate-project-service-action.php --execute MIGRATE_PROJECT_SERVICE
advance
update_stage=project-test-action
/usr/bin/php /opt/devmcp/bin/migrate-project-test-action.php --execute MIGRATE_PROJECT_TEST
advance
update_stage=sass-install
mkdir -p /opt/devmcp-tools/dart-sass
if [ ! -x /opt/devmcp-tools/dart-sass/node_modules/.bin/sass ] || [ "$(/opt/devmcp-tools/dart-sass/node_modules/.bin/sass --version)" != 1.94.2 ]; then
    /usr/bin/npm install --prefix /opt/devmcp-tools/dart-sass --no-audit --no-fund --save-exact sass@1.94.2
fi
advance
update_stage=sass-actions
/usr/bin/php /opt/devmcp/bin/migrate-sass-actions.php --execute MIGRATE_SASS_ACTIONS
# Preview-port reservations must exist before code that allocates a port is
# reloaded. This fixed, idempotent migration never modifies existing rows.
advance
update_stage=preview-port-migration
/usr/bin/php /opt/devmcp/bin/migrate-preview-port-reservations.php --execute MIGRATE_PREVIEW_PORTS
# Runtime catalogue is data-only; never starts project servers during migration.
advance
update_stage=runtime-catalog-migration
/usr/bin/php /opt/devmcp/bin/migrate-project-runtime-catalog.php --execute MIGRATE_PROJECT_RUNTIMES
update_stage=theme-catalogue-migration
/usr/bin/php /opt/devmcp/bin/migrate-theme-catalogue.php --execute MIGRATE_THEMES
advance
update_stage=template-catalogue-migration
/usr/bin/php /opt/devmcp/bin/migrate-template-catalogue.php --execute MIGRATE_TEMPLATES
# The agent address table is metadata only; the server remains ZeroTier-only.
advance
update_stage=agent-zerotier-address-migration
/usr/bin/php /opt/devmcp/bin/migrate-agent-zerotier-address.php --execute MIGRATE_AGENT_ZEROTIER_ADDRESS
advance
update_stage=remote-projects
/usr/bin/php /opt/devmcp/bin/migrate-remote-projects.php --execute MIGRATE_REMOTE_PROJECTS
advance
update_stage=local-android-runtime
/bin/bash /opt/devmcp/deploy/install-local-android-runtime.sh
# Provisioning runs inside systemd's read-only sandbox, unlike local CLI
# diagnostics. Reconcile only the approved workspace root and narrow unit
# drop-ins before the runtime is restarted.
advance
update_stage=workspace-access
/bin/sh /opt/devmcp/deploy/install-project-workspace-access.sh
# Install the fixed root-owned request processor; project instances remain disabled
# until explicitly selected and started through the typed MCP action.
advance
update_stage=project-service-install
/bin/sh /opt/devmcp/deploy/install-project-service-control.sh
advance
update_stage=doctor
DEVMCP_CONFIG=/etc/devmcp/global.php /usr/bin/php /opt/devmcp/bin/devmcp-doctor
# Remember the last successfully reloaded revision independently of the Git
# checkout. If a previous run updated files but failed before reload, retry.
# /run is root-controlled and resets harmlessly at boot.
advance
update_stage=restart
deployed_revision=
if [ -f "$deployed_revision_file" ]; then
    deployed_revision=$(cat "$deployed_revision_file")
fi
if [ "$old" != "$new" ] || [ "$sql_cutover" -eq 1 ] || [ "$deployed_revision" != "$new" ]; then
    restart_runtime
fi
# The web admin has a separate checkout; sync it on every scheduled run,
# including when the core is already current. The bridge deployer validates
# origins, clean worktrees, ancestry, PHP syntax and service permissions.
advance
update_stage=admin-bridge
if [ -d /opt/devmcp-admin/.git ]; then
    /bin/bash /opt/devmcp/deploy/distributed/apply-local-admin-bridge.sh
fi
advance
update_stage=verify-runtime
verify_runtime
printf '%s\n' "$new" > "$deployed_revision_file"
rollback_armed=0
advance
update_stage=complete
echo "devMcp update check completed: $old -> $new"
