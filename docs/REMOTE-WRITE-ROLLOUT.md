# Remote write pilot — independent approvals and rollout gates

Status (PR #38, October 28, 2026): implementation and disposable-workspace
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
- Paths containing `secret`, `credential`, `private`, `token`, `password`,
  `passwd`, `api_key`, `apikey`, `id_rsa`, `id_ed25519`, `id_ecdsa` or `id_dsa` in any
  case-insensitive path segment are also rejected by the server policy and
  queue validator **before content is stored in SQL**, even if explicitly
  allowlisted. The agent independently enforces the same protected-name deny.
  Common SSH private-key name variants are denied consistently at the
  enqueue gate, agent writer and read-only API (including `id_rsa.md`,
  `id_ed25519_backup.txt`, `id_ecdsa.md` and `id_dsa_backup.txt`).
  The existing remote read-only `workspace.read` and `workspace.stat` guards,
  together with `workspace.list` and `workspace.find` filtering, now reject
  these same protected-name markers (including common password and API-key
  spellings). The read APIs also deny common credential backup/variant
  names such as `.env_backup`, `.envrc`, `.npmrc.bak`, `id_rsa.old` and
  `certificate.pem.bak`, plus renamed hidden directories (for example
  `.ssh_backup`, `.aws-old`, `.config.copy`, `.git.bak`). Ordinary `.gitignore`
  and `.github` names remain visible; direct read/stat and list/find share one predicate.
  This is a filename-based control, not secret
  content detection: sensitive files must never reside under innocuous names
  in a remotely inspectable workspace.
  In addition, `workspace.read` and `workspace.stat` reject
  multiply-linked regular files (`nlink != 1`). A hardlink with a benign
  filename inside the workspace could otherwise expose the content **or size**
  of an agent-readable file outside it: symlink and `realpath()` checks alone
  do not establish this boundary. The disposable CI gate creates an
  external-file hardlink and verifies that neither read content nor stat
  metadata is disclosed.
- Arguments: `path` (relative), `content` (valid UTF-8, maximum 16 KiB),
  `expected_sha256` (current file content, 64 lowercase hex digits).
- Compare-and-swap with SHA-256, then replace through a temporary file and
  rename under a per-workspace `flock`. Missing files and SHA mismatches fail.
  The source is read through a file descriptor verified by `fstat()` against
  its initial inode, owner, group, mode and link count; the pathname is checked
  again immediately before the rename. This narrows replacement races but
  does **not** make concurrent writes by uncooperative local processes atomic.
  Disposable tests cover empty and maximum-size (64 KiB) existing text files.
- The agent validates the root-owned, non-group/world-writable PHP policy
  file **and every ancestor directory before requiring it**, including for
  read-only actions. A legacy installation with an untrusted policy file
  will fail closed until ownership and permissions are corrected.
  The agent bootstrap `/etc/devmcp/agent.php` is also executable PHP and
  receives the same ownership, ancestor and symlink validation **before**
  it is loaded. The stock installer creates it as `root:devmcp-agent 0640`;
  upgrading a legacy installation with unsafe permissions will now fail
  closed before any network contact. Check its ownership and ancestors as
  part of agent upgrade qualification.
  The agent lock state directory must be owned by the agent account and
  **private (0700, no group/other permissions)**. The normal installer already
  provisions `/var/lib/devmcp-agent` this way. The existing lock file must be
  an agent-owned, non-group/world-writable regular file with a single link.
  A second concurrent write returns `lock_busy` immediately rather than
  blocking the agent poll indefinitely. Writable workspace paths must be
  agent-owned and non-group/world-writable. Symlinks, traversal, executable
  targets, hardlinked target files (link count != 1) and protected paths are
  forbidden. `tempnam()` is also checked to ensure the temporary file really
  resides in the target parent directory: PHP may fall back to the system
  temporary directory when the parent is unwritable, in which case an atomic
  same-directory replacement cannot be guaranteed and the operation fails.
- Before replacing a file, the agent checks that the existing target is
  at most 64 KiB, valid UTF-8 and contains no NUL bytes. A binary file named
  .md or .txt is rejected even when its expected SHA-256 matches.
- Atomic file replacement preserves the original group and mode, or fails
  without renaming. POSIX ACLs and extended attributes are not preserved:
  use ordinary disposable files without custom ACLs or xattrs in this pilot.
- The server rechecks approval when claiming, not just when enqueuing.
  Queued write jobs older than 10 minutes expire without execution.
  Revoked, malformed, and expired write jobs are rejected and audited in the
  same SQL transaction, including only reason codes and deployment/job IDs;
  their queued content is scrubbed.
  A claimed job is not replayed if the result acknowledgement is lost.
- The SQL queue stores the **full requested content** until separately
  purged. Audit rows record deployment, file path, old/new SHA-256, size,
  job ID, and result outcome, **not content**. Do not use this pilot for
  confidential files until a retention policy and access review are complete.
- The result endpoint normalizes **write** results before database storage.
  A reported success must include a lowercase SHA-256 and a size in
  0..16384 **matching the original queued content** (digest and byte length).
  Failure includes a bounded error code. Arbitrary agent fields (notably
  `content`) are discarded, so completed jobs cannot accidentally retain
  plaintext in `result_json` after their queued input is scrubbed.
  Validly formatted but mismatched success becomes `write_result_mismatch`.
  A late success received after retention scrubbed the queued payload cannot
  be independently matched and becomes `write_result_unverifiable`; this is
  recorded as failed/unverified, **not evidence that the file was unchanged**.
  A malformed success becomes `invalid_write_result`. Operators must inspect
  the remote file before retrying any of these states to avoid overwriting a
  write that may have actually completed. Read-only result formats are unchanged.

## Payload retention maintenance — implemented but NOT scheduled

`bin/remote-write-retention.php` provides a bounded, transactional
maintenance operation. It marks queued writes older than 10 minutes as
expired, and scrubs the full SQL `arguments_json` from **claimed** writes
older than one hour. Claimed writes keep their original `claimed` state:
the agent may already have executed them, so their outcome is **unknown**.
This process must never requeue or replay such a job. A late agent result
can still update its status once; if it claims success after its queued content
was scrubbed, the server records `write_result_unverifiable` instead of an
unchecked success. Both scrub types produce audit entries without file content;
repeated sweeps are idempotent.

An explicit operator can invoke the one-shot CLI with the existing
least-privilege database identity:

```sh
DEVMCP_DISTRIBUTED_CONFIG=/etc/devmcp/mcp-distributed.php php /opt/devmcp-admin/bin/devmcp-remote-write-retention
```

This is deliberately **not installed as a timer or enabled by merging the
PR**. Before reading the executable PHP configuration, the CLI now enforces
root ownership, non-group/world-writable mode and non-writable, symlink-free
ancestor directories (the same pre-require trust rule as the agent). An
untrusted configuration fails closed, even if an environment override points
to it. A dedicated scheduler, account/permissions verification, and a real
sandbox run remain rollout tasks. `prepare-remote-write-pilot.sh` grants
the runtime identity the additional `UPDATE (arguments_json)` permission
needed for expiry and acknowledgements; the MySQL CI suite now executes
queue operations through an independently restricted user with these
column-level grants.

## Agent upgrade staging — not executed on real machines

The existing enrolled agent may predate `remoteLocalPolicyFileTrusted()`.
Do not call this function from an operator-side PHP migration script until
its presence on the target has been verified: legacy installations will
raise an undefined-function fatal error before changing their local policy.
This error alone is not evidence of a broken agent or an authorized write.

`deploy/distributed/upgrade-agent-readonly.sh` accepts one immutable 40-hex
commit SHA. It downloads and syntax-checks **all seven agent files**, then
loads the linked helper bundle to verify that the required policy, read,
write and dispatcher functions exist **before modifying the installation**.
It also validates root ownership/non-writable directories and refuses
symlinked pre-existing binaries. For the short replacement window, it
pauses an active polling timer and refuses to interrupt a running one-shot
agent service. If installation or its service start fails, the script
restores previous binaries, removes newly introduced files and resumes the
timer. After a successful service start, it resumes the timer and leaves
an operator-visible, private root-owned `ROLLBACK_SNAPSHOT` containing
previous binaries plus `.originally-absent` for newly introduced paths.

These safeguards narrow failed upgrades; replacing multiple files is
**not** an atomic transaction against power loss or `SIGKILL`. Preserve
the snapshot and verify the installed agent and local policy after any
interruption. This script never changes a local policy or server write
allowlist. Disposable CI exercises missing helpers, active service denial,
service-start failure rollback, timer restoration and successful snapshots.
**No physical agent upgrade is implied by passing CI.**

## Approval — both sides are mandatory

The v1 pilot intentionally permits **at most one globally write-enabled
remote deployment**, even across different projects. If a second deployment
has `enabled => true` in the server-side `remote_write.deployments` map,
**all remote writes fail closed** at discovery, enqueue, and queue claim;
existing queued write payloads are rejected and scrubbed on claim. Staged
entries with `enabled => false` do not revoke the sole approved pilot.
This safety gate is independent of the agent's own exact-path allowlist.

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

The MCP process reads `/etc/devmcp/mcp-distributed.php`. The existing
`deploy/distributed/enable-remote-mcp.sh` is **read-only setup only**: it
validates root ownership and safe ancestors before executing the admin PHP
configuration, rejects any source configuration that enables `remote_write`,
exports only the database keys and applies SQL grants **after** validation.
It never copies `remote_write` into the generated MCP configuration. This
script cannot activate the write pilot; such activation requires a separately
reviewed, explicit MCP configuration and agent policy change, neither of
which is automated or authorized by this draft. Without the opt-in key, MCP
never advertises the write action. Disposable CI exercises trusted config
export, disabled-policy stripping, enabled-policy refusal and untrusted PHP
config rejection.

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
do not allow writes to shared/group-writable workspaces. Retention cleanup is available as an opt-in CLI but no schedule is installed;
without a scheduler, orphaned write payloads remain until maintenance is run. Actor identity is
currently the MCP action endpoint at enqueue, and the machine ID at result;
per-human identity is not carried by the existing MCP session.
