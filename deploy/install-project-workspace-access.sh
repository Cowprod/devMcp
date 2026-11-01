#!/bin/sh
# Root-owned systemd sandbox allowance for devMcp's fixed local project root.
# It is deliberately independent of caller-provided MCP arguments.
set -eu
[ "$(id -u)" -eq 0 ] || { echo "Root required" >&2; exit 1; }

workspace=/srv/devmcp/workspaces
for path in /srv /srv/devmcp "$workspace" /etc /etc/systemd /etc/systemd/system; do
    [ -d "$path" ] && [ ! -L "$path" ] && [ "$(realpath -e -- "$path")" = "$path" ] \
        || { echo "Untrusted workspace/systemd ancestor: $path" >&2; exit 1; }
done
getent passwd devmcp >/dev/null
getent group devmcp >/dev/null

# Change only the dedicated root; never chown project descendants.
chown devmcp:devmcp -- "$workspace"
chmod 0750 -- "$workspace"

source=/opt/devmcp/deploy/systemd/devmcp-project-workspaces.conf
[ -f "$source" ] && [ ! -L "$source" ] || { echo "Missing trusted unit drop-in" >&2; exit 1; }
for unit in devmcp-tunnel.service devmcp-worker.service devmcp-http.service; do
    directory=/etc/systemd/system/"$unit".d
    [ ! -L "$directory" ] || { echo "Untrusted systemd drop-in path" >&2; exit 1; }
    if [ -e "$directory" ]; then
        [ -d "$directory" ] && [ "$(realpath -e -- "$directory")" = "$directory" ] \
            || { echo "Invalid systemd drop-in directory" >&2; exit 1; }
    else
        install -d -o root -g root -m 0755 "$directory"
    fi
    destination="$directory/50-devmcp-project-workspaces.conf"
    [ ! -L "$destination" ] || { echo "Untrusted systemd drop-in file" >&2; exit 1; }
    install -o root -g root -m 0644 "$source" "$destination"
done

systemctl daemon-reload
# The tunnel and worker must see this writable root, not just the root user's shell.
for unit in devmcp-tunnel.service devmcp-worker.service; do
    systemctl show "$unit" -p ReadWritePaths --value | grep -Fq "$workspace" \
        || { echo "Workspace systemd allowance missing: $unit" >&2; exit 1; }
done
echo "PASS: local project root writable for devmcp and allowed by systemd"
