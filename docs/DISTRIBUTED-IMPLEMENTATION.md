# Distributed implementation status

Branch: feat/distributed-architecture. This is an opt-in development branch; production remains on the existing local FileJobStore.

## Implemented on branch

- Logical MySQL/MariaDB schema in database/migrations/001_distributed_registry.sql.
- Isolated read-only PDO RegistryRepository for machines, projects, and deployments.
- JobEnvelope validator that rejects command fields, validates trusted policy digest, and resolves an existing action through the current ProjectRegistry.
- PHPUnit tests for accepted envelope, argv injection, wrong policy and unknown action.

## Not implemented yet

- Database migration runner and version table.
- Agent identity enrollment, credential rotation/revocation, TLS termination.
- Agent-facing HTTP API, leasing, fencing, durable attempt reconciliation.
- Agent PHP CLI daemon, service unit, local persistence.
- Administrative authentication, CSRF protection, Web UI.
- Integration into MCP project/action/job tools.
- End-to-end tests and production migration.

**Do not deploy this branch as a distributed server.** No remote job execution is possible yet.

## Migration preconditions

1. Verify MySQL 8 / MariaDB version, user privileges, storage and backups.
2. Apply schema to an isolated database only after review; never against production implicitly.
3. Ensure UTC on DB connection and server; no reliance on session local timezone.
4. Provision least-privilege SQL users separately for migrations and runtime.
5. Configure DB credentials outside Git, readable only by the service identity.
6. Run PHPUnit and static PHP lint on a checked-out branch before any merge.
7. Verify current local MCP, worker, job status/output, OTA publish and service operations remain unchanged.

## Known design constraints

The existing ProjectDefinition::fromArray() requires a local realpath. Therefore the distributed central registry must not instantiate remote-only deployments as local ProjectDefinition objects. The agent will create its own local registry after validating its local workspace. The central stores only logical project/deployment metadata and trusted action identities.

The current FileJobStore uses local flock and is not a distributed queue. It must not be mounted over the network or reused as a cross-machine queue. The distributed scheduler needs SQL transactions and leases, and a separate reconciliation state for unknown outcomes.

An agent must not accept an executable, argv, cwd, workspace or target from the remote job payload. JobEnvelope intentionally rejects unknown keys. Action definitions must be locally trusted, pinned to an approved policy revision, and scoped to a registered deployment. The present envelope is only a first validation building block, not a complete authorization mechanism.

Actions may execute build scripts from source repositories, so process isolation and permissions remain necessary even without arbitrary MCP shell access.
