# Remote write actions — security and rollout contract (v1)

Status: design gate only. No remote write capability is enabled by this document.

## Invariants

- The central registry selects an approved deployment; the remote agent independently checks its root-owned local policy and exact workspace.
- No arbitrary shell, interpreter snippets, or commands supplied by the MCP caller.
- Every write action is declared by name and typed arguments, with server-side and agent-side allowlists. Default deny.
- No traversal, symlinks, or writes outside a canonical workspace. Protected files (credentials, keys, Git internals, environment files) remain inaccessible.
- The remote service account is unprivileged; no sudo, privilege escalation, or inherited secrets.
- Audit records include request identity, deployment, action, validated argument digest, attempt, timestamps and outcome, without secret values.
- One claimed job is one execution attempt. Unknown or interrupted outcomes must not be automatically replayed.
- Destructive operations and deployment/service changes require separate policy capabilities and an explicit approval mechanism.

## Incremental rollout

1. Read-only inventory and tests (existing implementation).
2. Safe bounded write primitive: create or replace a regular UTF-8 workspace file, conditional on expected SHA-256, with atomic rename, strict maximum size and extension allowlist; no delete and no writes to executable/configuration paths by default.
3. Git status/diff, then controlled stage/commit in a dedicated project worktree; no force push, reset or branch deletion.
4. Project-specific test/build actions with fixed argv and fixed working directory, subject to timeout and output limits.
5. Explicitly approved deployment and service operations, separately audited.

## Acceptance gates before enabling writes

- Unit and integration tests for traversal, symlink races, protected paths, policy mismatch, duplicate deliveries, oversized payloads and concurrent writes.
- Independent agent-side authorization even if the central queue or DB is compromised.
- Rollback plan and verified no-impact path for existing read-only actions.
- Real end-to-end test against a disposable workspace, never the live caseRouter repository first.

No rollout should occur solely by merging this document.
