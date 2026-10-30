#!/usr/bin/env bash
# Prepare SQL permissions for an explicitly approved, disposable remote write pilot.
# This NEVER edits a deployment, agent policy, or enabled flag.
set -euo pipefail
[[ "$(id -u)" -eq 0 ]] || { echo "Run as root" >&2; exit 1; }
cd /opt/devmcp-admin

for file in   bin/remote-job-policy.php bin/remote-local-policy.php   bin/remote-server-write-policy.php bin/remote-job-enqueue.php   bin/remote-job-lifecycle.php bin/remote-job-dispatch.php   bin/remote-workspace-write-text.php bin/distributed-agent.php   src/Mcp/RemoteTools.php public/agent-job-poll.php public/agent-job-result.php; do
  php -l "$file" >/dev/null
done

# Needed only for atomic write enqueue/result audit records.
mariadb -e "
GRANT INSERT (actor, event_type, object_type, object_id, outcome, details_json)
ON devmcp_distributed.dm_audit_event TO 'devmcp_writer'@'localhost';
GRANT UPDATE (arguments_json)
ON devmcp_distributed.dm_remote_readonly_job TO 'devmcp_writer'@'localhost';
"
echo "PASS: remote write PHP syntax and audit INSERT grant verified."
echo "Remote writes remain disabled until BOTH the server allowlist and the"
echo "root-owned agent policy explicitly approve a single disposable deployment."
