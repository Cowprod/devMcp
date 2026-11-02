# Local project service control (TouchDeck V1)

Local only. Agent deployment is out of scope; it cannot block chattrackme.

## Boundaries
- dm_preview_port_reservation remains the sole authoritative port store.
- dm_runtime_type and dm_project_runtime explicitly select a runtime.
- Project must be active and enabled, assigned to the local hostname, with
  a canonical workspace under /srv/devmcp/workspaces/<project_id>.
- The MCP action project.service takes a fixed enumerated operation only:
  select-node, start, stop, restart, status, logs. No shell input exists.
- devmcp-worker preserves NoNewPrivileges=true; it writes a bounded queue item.
  A root-owned systemd timer separately verifies SQL and uses fixed argv for
  devmcp-project@<validated-id>.service; there is no sudo.
- Result files are root-owned under /run/devmcp-project-results, readable
  but not writable by the MCP worker. Logs have size limits and redaction.
- App process runs as devmcp, with isolated StateDirectory, narrowed writable
  workspace, and no ambient capabilities.
- Listener uses a verified local ZeroTier address. Node programs must honor
  HOST; chattrackme rejects 0.0.0.0 explicitly.

## Rollout
The authorized self-updater installs templates, timer, and SQL action.
Installation never starts an application automatically.

For an active project with a checked-in server.js:
1. project.service operation select-node selects Node explicitly (stopped).
2. project.service operation start changes desired_state, enables the systemd
   instance across reboots and verifies local HTTP 200.
3. status reports systemd process state plus local host HTTP check; logs
   returns a bounded journal excerpt.
4. stop disables the systemd instance; restart requires desired running.

Use MCP action_start, then job_status and job_output to retrieve results.
A queued request is never proof of service availability. The timer processes
a bounded batch about every 15 seconds.

Per-project secrets and OLLAMA_URL belong in root-managed optional
/etc/devmcp/project-env/<project_id>.env files, never in SQL.
PHP -S is for development previews only, not production.

## Acceptance checks (not achieved merely by CI)
- CI plus migration pass, updater stage complete, deployed Git SHA confirmed.
- chattrackme workspace synced, selected runtime and start result verified.
- systemd active, TCP 18373 bound only to 192.168.194.189, GET /health 200.
- HTTP GET succeeds from a distinct ZeroTier peer, not just TouchDeck itself.
- stop, restart, reboot persistence, journal redaction, existing service
  non-regression confirmed.
Agent work follows after local validation, without blocking it.
