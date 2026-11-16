# Mobile administration: persistent sessions and first registry write batch

The original `/api/admin/v1/session` login without additional fields remains read-only, with a one-hour access token. It never receives write scope or a refresh token implicitly. Existing catalogue and agent protocols are unchanged.

## Persistent login

`POST /api/admin/v1/session` accepts:

```json
{"username":"admin","password":"<entered once>","persistent":true,"device":{"id":"<random stable device id>","label":"iPhone","platform":"ios"},"scopes":["admin:read","admin:write"]}
```

Only `['admin:read']` and `['admin:read','admin:write']` are accepted. The existing `AdminSession::verify` and login throttle are reused. The response adds `refresh_token` and `session` to the existing access-token response. The password must be discarded immediately. Device IDs are labels/binding metadata, not attestation or independent authentication.

```json
{"api_version":1,"access_token":"<64 hex>","token_type":"Bearer","scope":"admin:read admin:write","expires_in":3600,"expires_at_utc":"<UTC>","refresh_token":"<session id>.<random secret>","session":{"id":"<32 hex>","device":{"id":"<device id>","label":"iPhone","platform":"ios"},"scopes":["admin:read","admin:write"],"created_at_utc":"<UTC>","last_used_at_utc":"<UTC>","expires_at_utc":"<absolute UTC>","state":"active"}}
```

`POST /session/refresh` takes `{"refresh_token":"...","device_id":"..."}`. Each success rotates the refresh token and issues a new access token without extending the absolute session lifetime. The client must serialize refreshes, securely store the new refresh token before enabling administration, and never automatically retry a refresh after an ambiguous network failure. It must not keep using the previous refresh token.

The server stores current and consumed refresh SHA-256 digests, never their raw values. Records are private 0600 files in the existing 0700 admin session directory, with serialized locked updates and fsync. Reusing an already consumed refresh revokes the family and all its access tokens. Random invalid secrets or a different device ID cannot revoke another family. A maximum of 4096 rotations bounds each record; exhaustion requires login again.

Session validity checks the current administrator credential version on every access and refresh. Password resets invalidate all associated credentials immediately. Session IDs and metadata may be listed; no hashes or credentials are returned.

The default absolute lifetime is 30 days. Root may set `/etc/devmcp-admin/mobile-auth.php` to `<?php return ['session_max_age_seconds'=>2592000];` (minimum one hour, maximum one year). This applies to new sessions; existing absolute deadlines never extend through refresh. No SQL migration is needed.

## Session management

- `GET /sessions`: read scope; returns `sessions` and `current_session_id`.
- `DELETE /sessions/{id}`: write scope and JSON `{"confirmation":"REVOKE_SESSION"}`; revoke a lost device.
- `DELETE /session`: revoke the current access token and its device family, if present.
- `POST /session/revoke`: revoke the current family using JSON `refresh_token` and `device_id`, even when its access token has expired. Intended for voluntary logout.

Revoked/expired sessions return 401. Network, timeout and server errors are separate conditions; the app must keep a locked session on a network failure instead of interpreting it as an invalid password. Tokens must never be written to URLs, logs, Web storage, public files or app backups. Refresh secrets belong in OS-protected storage; access tokens stay in memory.

## First coherent write batch

Data writes require an explicitly granted `admin:write` scope. A valid old/read-only token receives 403 before any writer connection is made. All fixed routes delegate to the same `RegistryEditor` methods as the Web admin; there is no generic action route, command execution or SQL shortcut. Audit events retain the existing transaction and carry `mobile-admin:<session id>` as actor; the Web default remains `web-admin`.

| Method | Path | Fields / behavior |
|---|---|---|
| GET | `/projects` | Full admin project projection, including registry-only entries |
| GET | `/assignments` | Recorded deployments; includes authorized workspace path and enabled state |
| POST | `/machines` | `name`, `os`, `architecture`, `capabilities`; creates a pending registry machine, does not enroll/start an agent |
| PATCH | `/machines/{id}` | `name`, `os`, `architecture` |
| PUT | `/machines/{id}/capabilities` | `capabilities` containing all three existing boolean switches |
| POST | `/machines/{id}/approve` | `confirmation: APPROVE_MACHINE`; existing enrolled-identity validation remains mandatory |
| POST | `/projects` | `id`, `description`, `repository_url`; registry registration, not repository creation or provisioning |
| PATCH | `/projects/{id}` | `description`, `repository_url`; existing registry metadata service |
| POST | `/assignments` | `project_id`, `machine_id`, `workspace_path`; execution remains disabled |
| PATCH | `/assignments/{id}` | `workspace_path`; enabled assignments cannot be edited |

`registry_metadata` in project details identifies editable registered metadata separately from the deployed Git source. Absent metadata remains null. Changing registry metadata does not rewrite a checked-out repository or the remote build configuration.

Unsupported deletion, machine disable/revoke, deployment start/restart, integrated project creation, agent diagnostics and build dispatch are not exposed as arbitrary operations. The Web admin currently has no shared deletion/disable service, and its legacy HTTP MCP bridge does not listen on this host. These require subsequent coherent service/transport batches; unsupported methods fail explicitly. No test may delete or disable a real machine/project.

CORS now permits PATCH and PUT in addition to the previous methods, still only on the same exact local Cordova origins. HTTPS, ZeroTier-only binding, Web session/CSRF guards and agent authentication are preserved. The deploy checker uses a legacy read token and now expects 403 on a supported write route.

## Explicit multi-machine deployments (API v1)

Every call uses the existing TLS and rotating mobile bearer session. Reads require admin:read;
writes require the explicitly granted admin:write scope. DeploymentService is shared with
the Web UI and MCP tools; no project-level implicit machine selection occurs.

| Method | Path | Result |
|---|---|---|
| GET | /deployment-targets | Instance-protocol-eligible machines |
| GET | /deployments | Managed deployments list, secret-free |
| GET | /deployments/{deployment_id} | Instance detail with recent jobs |
| GET | /deployments/{deployment_id}/jobs/{job_id} | Sanitized result scoped to the instance |
| POST | /deployments | Register instance, HTTP 201 |
| PATCH | /deployments/{deployment_id} | Update desired configuration with current revision |
| POST | /deployments/{deployment_id}/actions | Start a typed action on exactly one instance |

Registration requires project_id, machine_id and confirmation ADD_DEPLOYMENT.
Optional configuration and workspace_path obey the shared business validations.
Configuration requires deployment_id matching the URL and integer revision.
Omitting variables preserves existing secret values; configuration.clear_variables=true clears them.
Actions require deployment_id matching the URL, action from create/sync/deploy/status/logs/stop/restart,
commit of exactly 40 hex characters for create/sync/deploy, and runtime static/php/node only for deploy.
Stop additionally requires confirmation STOP_DEPLOYMENT. No free-form execution endpoint exists.

Responses return variable names, not values. Raw agent logs are intentionally omitted on the mobile
job-result route because application logs can contain sensitive data.
The authenticated Web administration remains the existing log viewer.
Invalid/mismatched instance IDs or stale revisions are rejected; clients must not choose a machine implicitly.
