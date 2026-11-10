# TouchDeck: root-approved central operator socket (three-demo lab only)

**Status: implementation, NOT installed, no deployment evidence.** The central
HTTPS hosting-poll/result routes and the existing root-only SQL enrollment are
independent prerequisites. This opt-in does **not** change caseRouter or grant
the remote agent new privileges.

## Security boundary

The existing `bin/devmcp-hosting-lab-batch` CLI remains root-only. This
optional IPC path allows an explicitly authorized **local** devMcp project action
to ask a separate systemd root worker to perform ONLY its two existing SQL
transactions for the three named demos. It does not run any remote commands.

- Socket: `/run/devmcp-hosting-central/operator.sock`, root:`devmcp` 0660,
  socket-activated with systemd `Accept=yes`. All members of the local
  `devmcp` group can request the narrow capability **once enabled**. Do not
  install if that group is not an acceptable trust boundary.
- Root worker: `bin/devmcp-hosting-central-operator`, with fixed root-owned
  PHP source paths, no untrusted Composer autoload, exact request schema,
  and the existing central enrollment/grant, revision and transaction checks.
- A separately granted `hosting.lab.inspect` action uses the same local
  socket to read the existing `HostingLabCentralInspection` inventory:
  exact machine identity, three deployment grants, latest SQL job per
  runtime and delivery state. It sends no SHA or caller-controlled query.
  Its receipt always says `deployment_complete=false`; a SQL job state
  is not an independent HTTP/WebSocket health check. It reports missing
  enrollment as a blocker rather than attempting to create one.
- Allowlist: exactly `caseRouter`, `lab-static`/17601, `lab-php`/17602,
  `lab-node`/17603. The packet accepts only phase `prepare` or `activate`,
  a 40-hex revision, exact machine name, and phase-specific consent.
  No caller-supplied shell, path, port, SQL, project, deployment ID or key.
- Preparation and activation are separate calls; the existing SQL state
  gate refuses activation unless the latest three preparations succeeded at
  the same SHA. A successful activation request does not mean an application
  is live: local root policy, signed peer checks, commit and watchdog stay
  independent.
- A default installation or self-update must **never** enable the socket or
  silently add project actions. The operator must explicitly opt in the
  root configuration AND install the new socket AND allowlist local actions
  in the devMcp project definition.

## Explicit bootstrap (administrator review required)

First ensure the already-prepared TouchDeck HTTPS route installation has
completed successfully and the existing SQL enrollment is valid.

An administrator may add these two extra keys to the **root-owned**
`/etc/devmcp-admin/distributed.php` return array:

```php
'hosting_lab_operator_socket_enabled' => true,
'hosting_lab_operator_machine_name' => 'caseRouter',
```

These keys are independent of
`'remote_hosting_queue_enabled' => true` and deliberately have no default.
Protect `/etc`, `/etc/devmcp-admin`, the config and the checked-out central
source paths against non-root writes. Changing this file is a privileged
operator decision, not an MCP action.

After this approval, a TouchDeck root operator installs only the two new
namespaced systemd units:

```bash
cd /opt/devmcp && sudo bash deploy/distributed/install-hosting-central-operator.sh --root-approved
```

The installer checks root-only approval, code/config owners and permissions,
**the entire root-owned, non-group-writable `/opt/devmcp/deploy/distributed` source
chain** (not only the two systemd leaf files), explicit flags, devmcp group,
existing HTTPS routes, Nginx syntax and preexisting conflicting systemd units.
It acquires an exclusive, persistent root-owned lock in
`/run/lock/devmcp-hosting-central-operator.lock` before inspecting or
mutating the socket state: a concurrent bootstrap is rejected without
touching systemd. It records whether the socket was already **enabled**
and **active** independently. New unit files are staged on the same
filesystem and moved into place only once complete. If the subsequent
permission or content check fails after a successful rename, the newly
created unit is already in the rollback ledger; a skipped `mv -n`
is never treated as our own unit. If the installation, daemon reload, socket
start or ACL verification fails, the exit trap attempts to restore both
original socket states and removes **only units created by that invocation**
that still match the trusted templates. A pre-existing matching unit is
never removed. Rollback errors are reported on stderr, not hidden; an
administrator must reconcile any failed restoration before retrying. This
does not replace actual privileged systemd verification.
The separate HTTPS installer applies the same source-path protection before
executing its PHP renderer as root; any writable/symlinked parent fails closed.
If a preflight rejects the checkout, repair its ownership and permissions
administratively rather than weakening these checks. It exposes a local Unix socket only;
it does not enqueue jobs, restart a service, or deploy any app on caseRouter.

To invoke from the devMcp connector, an administrator must **separately**
include `hosting.lab.inspect`, `hosting.lab.prepare` and `hosting.lab.activate` in the
`shared_actions` array of an authorized *local* project in
`/etc/devmcp/global.php` and reload its worker after reviewing that grant.
The default shared action definitions alone do not expose the operations.
These actions accept only a `revision` SHA parameter. They cannot operate
on arbitrary machines or runtimes.

On systems with the explicit opt-in, the unprivileged CLI clients are:

```bash
php /opt/devmcp/bin/devmcp-hosting-central-submit inspect
php /opt/devmcp/bin/devmcp-hosting-central-submit prepare <40-hex-revision>
php /opt/devmcp/bin/devmcp-hosting-central-submit activate <same-revision>
```

Do **not** invoke activate until the three SQL preparation receipts show
`succeeded`. Do not retry automatically after any `claimed` or successful
initial activation: the existing central and local fences reject or require
root reconciliation.

## Validation and scope

Pure tests:
`php tests/distributed/hosting-central-operator-contract.php`.
The CI tests syntax, the strict protocol including inspect-only messages, separate consent, ownership
contracts, denial when the executor is invoked without root, and systemd
unit syntax. They do not attest live systemd installation or router execution.

No change to WAN/LAN, routes, NAT, firewall, DNS, router boot services, or
the existing remote read-only queue. The same local caseRouter root-only
gateway, transaction log, recovery/rollback and independent ZeroTier peer
proof remain mandatory. On current evidence all three demos are **0/3**.
