# Remote write pilot — independent approvals and rollout gates

Status (PR #38, October 27, 2026): implementation and disposable-workspace
integration tests are in the branch. **NOT deployed or enabled on any real
agent.** This is a gated pilot, not yet general-purpose remote development.

## Implemented v1 capability

The existing `action_start` -> SQL queue -> HTTPS agent poll -> local
policy -> executor -> HTTPS result -> `job_status` flow supports the
explicitly gated `workspace.write_text` action.

- Replace **only an existing regular .md or .txt file**; no create, delete,
  shell, Git mutation, executable write or arbitrary path.
- Exact, separately maintained server and agent path allowlists. Default deny
  even after upgrading the code. A remote project is offered the write action
  only if exactly one active deployment is visible and server-opted in.
- Arguments: `path` (relative), `content` (valid UTF-8, maximum 16 KiB),
  `expected_sha256` (current file content, 64 lowercase hex digits).
- Compare-and-swap with SHA-256, then replace through a temporary file and
  rename under a per-workspace `flock`. Missing files and SHA mismatches fail.
- The agent requires a root-owned, non-group/world-writable local policy file;
  an agent-owned, non-group/world-writable private state directory for locks;
  and agent-owned workspace directories and target. Symlinks, traversal,
  executable targets and protected paths are forbidden.
- The server rechecks approval when claiming, not just when enqueuing.
  Queued write jobs older than 10 minutes expire without execution.
  A claimed job is not replayed if the result acknowledgement is lost.
- The SQL queue stores the **full requested content** until separately
  purged. Audit rows record deployment, file path, old/new SHA-256, size,
  job ID, and result outcome, **not content**. Do not use this pilot for
  confidential files until a retention policy and access review are complete.

## Approval — both sides are mandatory

For a disposable deployment (never `caseRouter` first), create a sandbox
workspace that already contains `docs/smoke.md` and register it as a single
active deployment. Replace the placeholder ID below with the actual **32-hex
deployment ID**. Configure the following `remote_write` key in
`/etc/devmcp-admin/distributed.php`:

```php
'remote_write' => [
    'enabled' => true,
    'deployments' => [
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => [
            'enabled' => true,
            'paths' => ['docs/smoke.md'],
        ],
    ],
],
```

The MCP process reads `/etc/devmcp/mcp-distributed.php`; the existing
`deploy/distributed/enable-remote-mcp.sh` now copies that optional key
when regenerated. Without it, MCP never advertises the write action.

Independently, the agent's **root-owned**
`/etc/devmcp/remote-readonly-policy.php` must contain the same deployment
ID and the exact pinned workspace:

```php
return [
    'enabled' => true,
    'deployments' => [
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa' => [
            'workspace_path' => '/srv/devmcp-write-sandbox',
            'actions' => ['git.head', 'workspace.read', 'workspace.write_text'],
            'allow_write' => true,
            'write_paths' => ['docs/smoke.md'],
        ],
    ],
];
```

The root-owned `/etc/devmcp/agent.php` must also point `state_dir` to a
preexisting **agent-owned private directory** (not group/world writable).
The workspace root, `docs/` directory, and file must also be agent-owned.
The dedicated `deploy/distributed/prepare-remote-write-pilot.sh` installs
the SQL audit INSERT grant but **does not enable any deployment**.
`deploy/distributed/upgrade-agent-readonly.sh` now includes the write
dispatcher/primitive in pinned SHA upgrades, without enabling writes.

## Acceptance checks

1. Existing read-only actions and all baseline CI remain unchanged.
2. Standalone security integration exercises server, agent, queue,
   symlink/path, size, SHA conflict, duplicate delivery and private state.
3. MySQL 8 integration exercises the real queue claim/result implementation,
   server revocation, stale-job expiry, result audit and legacy read-only.
4. Explicit human-approved physical test against a disposable remote
   workspace, including denied requests before local policy activation.
5. Verify rollback by disabling either policy flag, with no impact on reads.
6. Only after these checks, decide whether to expand to source-file writes,
   reviewable diffs, controlled Git commits and project-specific tests.

The first three are CI gates; the last three are **not yet completed**. No
merge, remote write enablement or live `caseRouter` modification is
authorized by this document.

## Open security/operations limits

This v1 assumes the unprivileged agent account and its private files remain
trustworthy. Full multi-user filesystem race resistance would require
platform-specific file-descriptor-based operations (`openat` + no-follow);
do not allow writes to shared/group-writable workspaces. No automatic
cleanup/retention of queued content is implemented yet. Actor identity is
currently the MCP action endpoint at enqueue, and the machine ID at result;
per-human identity is not carried by the existing MCP session.
