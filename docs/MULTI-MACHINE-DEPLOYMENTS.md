# One project, multiple machine deployments

MCP server feature version: 0.6.0.

A project remains one `dm_project` record and one registered GitHub repository. A managed instance is identified by the existing `dm_deployment.id`. Version, workspace, runtime, desired configuration and jobs belong to that deployment, not to a global project target. Version 1 permits one deployment per project/machine pair, preserving the existing registry constraint. Several machines may use the same port.

## Compatibility and rollout

The migration keeps every existing deployment ID, workspace, metadata, service name and job ID. The managed table primary key changes from project to deployment, retaining unique project/machine binding. Existing jobs are backfilled by their exact project/machine pair. A private SQL backup is synchronized before changing an old primary key; the privileged updater applies this migration. No existing instance is redeployed, stopped or moved by migration. Orphan historical jobs are not assigned an invented target.

Agents explicitly negotiate `instance_protocol: 1`; supported revisions report a bounded `projects-v1:<SHA>:inst1` version. An old central still receives the original ping. New-instance commands are a separate `instance` envelope: older agents reject them before side effects. New targets require successful instance protocol negotiation. The central server must be deployed first, then agents updated through their normal updater. Legacy single-instance calls retain their old formats. Ambiguous calls require a deployment ID; a local project remains explicitly addressable with `deployment_id: "local"` in the legacy action tools.

Existing installations absent from the registry are **not adopted** by registration. Managed legacy workspaces may be controlled after migration because their recorded ready state and existing machine/project binding are retained. An unrelated pre-existing directory, foreign service or conflicting deployment ID is refused. Workspace relocation after the first job is not supported: no implicit move of persistent data.

## MCP workflow

1. `deployment_targets` returns active, hosting-enabled machines whose project agent negotiated instance protocol 1.
2. `deployment_list` optionally filters by project; `deployment_describe` returns one explicit instance and its recent jobs, including requested commits.
3. `deployment_register` takes project, machine_id, confirmation `ADD_DEPLOYMENT`, optional workspace_path and configuration. It records the instance but does not create a repository or touch a workspace.
4. `deployment_action_start` with action `create` and an exact source commit prepares that instance's workspace. Follow the job with `job_status`.
5. The same tool with action `deploy`, exact commit and runtime `static`, `php` or `node` deploys only that instance.
6. Actions `sync`, `status`, `logs`, `stop`, `restart` remain instance-scoped. Sync needs a commit. Stop/restart operate on the existing service and active version; they do not activate a merely staged revision. Stop also disables automatic launch at reboot; restart/deploy enables it explicitly.
7. `deployment_configure` requires the current configuration revision. An in-flight operation blocks edits. Changes apply with the next deploy. Omitting variables preserves existing values; the MCP field `clear_variables: true` explicitly clears them (do not combine it with replacement values). In the Web form, an explicit `{}` also clears them. Omitted port preserves the current configuration; a null port uses the agent's stable automatic allocation.

A source-bearing job first enters `preparing`. The existing central worker uses its own GitHub SSH identity and existing source snapshot validator, then queues the authenticated agent job. PHP-FPM never needs the private GitHub key. Failed source preparation never queues an agent operation or changes another instance. No generic command execution endpoint is added.

## Configuration boundaries

- Ports stay in the existing approved hosting window **12000–19999**. Port collisions on the same machine are rejected. The agent checks occupied changed ports before stopping its existing service.
- A custom workspace is a direct slug under that machine's approved project hosting root. Paths outside that root, links and another deployment's workspace are refused.
- Up to 64 uppercase environment names and 4096 bytes per value, without control characters. Engine variables (`HOST`, `PORT`, `DATA_DIR`, `MOBILE_RELEASES_FILE`, `PATH`, `HOME`, `USER`, `SHELL`) and loader variables are reserved.
- Values stay in the private SQL/job transport and OS-private runtime configuration. Public projections return variable **names only**. Linux systemd reads a private 0600 environment file; macOS LaunchAgent plists are 0600. The engine never inserts values into a public service unit, response or audit event.
- Audit identifies the deployment and operation, without values. Project capabilities and enrolled-machine authentication remain enforced. Observation timestamps are distinct from desired configuration and applied configuration revisions.

## Web administration

The existing Assignments panel includes an instance section using the same DeploymentService as MCP. It provides registration, per-machine configuration, exact-commit deployment, status/log requests, stop/restart and individual job history. The Result button reads the authenticated job result or requested log; raw requests, Git blobs and variable values are never returned by this endpoint. Existing Web sessions and CSRF checks protect every write. Secret values are never preloaded into forms. Existing local service controls and inactive assignment declarations remain separate and preserved.

The catalogue adds a `deployments` collection without removing existing single-instance fields. A multi-remote project has no implicitly chosen machine URL. Per-machine metadata is retained in administrative associations; one instance cannot overwrite another's version or state.

## Validation boundary

Use disposable projects and two enrolled machines. Compare the second instance's active revision, HTTP output and data before/after updating or stopping the first. Test source failures, bad ports, stale configuration revisions, ambiguous legacy calls and malformed/wrong-machine completions. Do not use existing LED or other real application installations as fixtures.
