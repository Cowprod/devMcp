# Mobile administration API v1

Persistent device sessions and the first explicit registry write routes are documented in [ADMIN-MOBILE-SESSIONS.md](ADMIN-MOBILE-SESSIONS.md). The legacy username/password login described below remains read-only and never receives persistence or write permission implicitly.

## Audit and reuse

The existing administration entrypoint is `public/distributed-admin.php`, served only on the explicit ZeroTier TLS address by `deploy/distributed/nginx-admin.conf.example`. It authenticates through `AdminSession`, `/etc/devmcp-admin/web-auth.php` (bcrypt password hash plus reset version), and HttpOnly/Secure/SameSite=Strict PHP sessions in `/var/lib/devmcp-admin-auth`. There is one administrator account; no pre-existing per-project ACL or read-only Web role. Existing Web operations remain behind that session and their CSRF checks.

Existing HTTP interfaces are query endpoints on `/`: `project_service_api` (request/poll), `remote_jobs`, `adb_image`, `adb_log`, `adb_status`; enrollment and machine-scoped bearer routes under `/v1/enrollment` and `/v1/agent`; and separately issued IPA/APK download URLs. These are not a mobile administration REST API. No equivalent machine list/detail or project detail REST route existed. The public live catalogue at port 12838 (`/?format=json`) already provides project IDs, machines, URLs, provisioning and Web observations, platform flags, latest mobile builds and verified artifact links. Its schema and access policy are unchanged.

Authoritative services reused:

- `RegistryRepository`: `dm_machine`, `dm_project`, `dm_deployment`, and workspace diagnostics. Its existing safe field selections exclude agent identities and credentials.
- `MachineCapabilities`: declared `hosting`, `cordova_android`, `cordova_ios` switches (`dm_machine_capability`). These declarations are not proof that every tool is installed.
- `ProjectRuntimeCatalog`: runtime choices and desired state (`dm_project_runtime`). Only type and desired state are projected, never entrypoints or paths.
- `LiveProjectCatalogueClient`: reads the already deployed live catalogue on loopback, at the current `devmcp-projects` port from `dm_preview_port_reservation` for the central hostname. That existing service invokes the authoritative core inventory shared with MCP `project_list`, reusing provisioning (`dm_project_provisioning`), remote project metadata (`dm_remote_project`), preview observations, and the mobile build/artifact projection. No extra artifact store or provisioning implementation is introduced. The deployed server runs MCP through its stdio tunnel; the old admin MCP HTTP bridge points to a loopback port that does not listen. It is therefore not used or changed by this extension.
- `PublicProjectCatalogue::export`: the existing public projection, consumed through the live catalogue service.

Assignments combine the current MCP inventory, the existing distributed deployment registry, and the current remote-project machine. Multiple deployments remain visible, including disabled ones; `sources`, `deployment_id` and `enabled` distinguish recorded associations from enabled execution. Counts are distinct projects per machine. `local` is the existing central-host capability key; other machine IDs are the SQL stable IDs.

There is no remote hostname column, separate project display name, or project environment column. Those values are `null`; the local hostname comes from the running server. The local central server has no agent heartbeat/version row: these are `null`, and `connection: online` means the server handling the request is running. Remote connection uses the same heartbeat window as the Web UI (180 seconds, 30 seconds future tolerance), independent of the separate registry status. No connection status proves application health.

## Endpoints

Base in the current lab: `https://192.168.194.189/api/admin/v1`.

| Method | Path | Result |
|---|---|---|
| POST | `/session` | Authenticate using the existing admin username/password; issue a temporary `admin:read` bearer |
| DELETE | `/session` | Revoke the current bearer |
| GET | `/machines` | Machine list, without per-machine project arrays |
| GET | `/machines/{id}` | Machine detail including associated projects |
| GET | `/projects/{id}` | Project detail including all recorded machine associations |
| OPTIONS | API paths | Cordova CORS preflight only; no data or credential issuance |

These are the original read-only paths. There are no unversioned aliases. Explicit mobile write routes are documented separately and require an opt-in `admin:write` device session; legacy/read-only credentials receive 403 on those routes. Unsupported methods receive 405 after authentication. Unknown resources return 404; missing, expired, forged or revoked credentials return 401; blocked origins/HTTP return 403; throttled login 429; dependencies unavailable 503. Errors are `{"api_version":1,"error":{"code":"authentication_required"}}`, with fixed codes and no exception text. Data responses include `api_version: 1` and `generated_at_utc` (time of collection start; individual observation/build dates remain separate). All responses are `Cache-Control: no-store`.

### Login

```http
POST /api/admin/v1/session
Content-Type: application/json

{"username":"admin","password":"<entered by user>"}
```

201 response:

```json
{"api_version":1,"access_token":"<opaque 64 hex characters>","token_type":"Bearer","scope":"admin:read","expires_in":3600,"expires_at_utc":"<UTC ISO8601>"}
```

Every data request uses `Authorization: Bearer <access_token>`. Tokens are random 256-bit opaque values; only their SHA-256 filename and account/scope/expiry metadata are stored server-side, in the existing private session directory, mode 0600. Token records cannot create Web sessions, authorize agents, or grant write access. They expire after one hour; logout deletes the record. Resetting the administrator credential version revokes them, like existing Web sessions. Expired token files are removed on subsequent issuance. Login is limited to five attempts per source IP in a five-minute window using a shared locked file, including successful attempts; 429 includes Retry-After: 300. Request bodies are limited to 8 KiB.

### Machine schema

```json
{
  "api_version":1,
  "generated_at_utc":"<UTC ISO8601>",
  "machine":{
    "id":"<SQL id or local>","name":"Mini","hostname":null,
    "os":"macos","architecture":"arm64","agent_version":"<reported version or null>",
    "status":"active","connection":"online","last_seen_at_utc":"<UTC ISO8601 or null>",
    "capabilities":{"hosting":true,"cordova_android":true,"cordova_ios":true},
    "hosting_types":["static","php","node"],"project_count":1,
    "projects":[{"id":"<project id>","name":null,"description":"<description>"}]
  }
}
```

`GET /machines` returns the same records in `machines: [...]`, without `projects`. Undeclared capabilities and hosting types are `null`, not inferred false. Declared hosting types describe policy, not detected dependencies.

### Project schema

```json
{
  "api_version":1,
  "generated_at_utc":"<UTC ISO8601>",
  "project":{
    "id":"<project id>","name":null,"description":"<description>","github":"<safe GitHub HTTPS URL or null>",
    "kind":"remote","provisioning_state":"active",
    "deployment_state":"unknown","deployment_checked_at_utc":null,
    "runtime_type":"node","desired_state":null,"environment":null,
    "port":12000,"url":"http://<ZeroTier IP>:12000/",
    "platforms":{"web":true,"ios":true,"android":true},
    "mobile_configuration":{"known":true,"revision":"<Git SHA or null>","errors":[]},
    "mobile_builds":{
      "ios":{"status":"succeeded","job_id":"<id>","target":"device","version":"1.0.0","revision":"<Git SHA>","created_at_utc":"<UTC>","started_at_utc":"<UTC>","finished_at_utc":"<UTC>","available":true,"availability":"available"},
      "android":{"status":"not_built","job_id":null,"target":"android","version":null,"revision":null,"created_at_utc":null,"started_at_utc":null,"finished_at_utc":null,"available":false,"availability":"not_available"}
    },
    "artifacts":[{"platform":"ios","kind":"ipa","job_id":"<id>","version":"1.0.0","bundle_id":"<id>","revision":"<Git SHA>","size":123,"sha256":"<SHA256>","build_url":"<HTTPS installation landing page>","download_url":"<HTTPS binary URL>","available":true}],
    "assignments":[{"machine_id":"<SQL id>","machine_name":"Mini","sources":["inventory","remote_project"],"deployment_id":null,"enabled":null}]
  }
}
```

`deployment_state` is the existing observed HTTP state (`http`, `tcp`, `offline`, `not_checked`, `unknown`), not an inferred successful deployment. Registry-only entries have unknown fields `null`, and empty artifacts/assignments. `mobile_builds`, platforms and artifacts keep the existing catalogue semantics: a new build supersedes the previous result; only currently available IPA/APK links are returned. Simulator archives are not IPAs. See `PROJECT-CATALOGUE.md` for the mobile build status vocabulary. No workspace path, command, build log, raw SQL/agent configuration, key, token or sensitive environment value is projected.

## Cordova iOS integration

1. Keep existing catalogue, filters and favorites untouched. Request admin details on demand using stable machine/project IDs; use `/machines` to resolve display names to stable IDs. Never assume the display name is a hostname or an ID.
2. Prompt for the existing administrator username/password. POST JSON to `/session`; keep the resulting bearer in memory, never in the repository, bundle, URL, logs or localStorage. Discard the password after login. A 401 requires a new login; there is no permanent refresh credential.
3. Use `fetch(url, {headers:{Authorization:'Bearer '+token}, credentials:'omit'})`. POST login also uses `Content-Type: application/json`. DELETE `/session` on logout, then clear memory. Keep the same API origin fixed/validated before sending credentials.
4. The API allows exact WebView origins `app://localhost` (iOS) and `https://localhost` (Android secure local origin); preflight allows Authorization/Content-Type, GET/POST/PATCH/PUT/DELETE/OPTIONS. It sends no credentialed CORS and accepts neither `*` nor `null`. Explicitly configure Cordova scheme `app` and hostname `localhost` on iOS if needed. An Android app using insecure file mode must use a secure local origin before consuming this API. Native HTTP clients may omit Origin but must still authenticate.
5. The device must join ZeroTier and trust the installed lab CA (full trust enabled on iOS). HTTPS certificate/hostname verification must stay enabled. Installation links retain their existing download authorization semantics; use `build_url` for iOS installation and `download_url` for APK.

Web cookies, HTTP Basic and agent bearer tokens do not authenticate this API. Administrative data changes require the separately granted `admin:write` scope and the existing business validations; `admin:read` is never upgraded implicitly.

## Deployment and verification

No SQL migration or new database grant is needed: all queried tables are existing Web-admin read sources. The `devmcp-projects` live catalogue must be running with a local SQL port reservation and the current mobile metadata schema. Its HTTP read uses only loopback, no redirects/proxy, a 25-second deadline, a 2 MiB bound, and a maximum source age of 60 seconds; errors return 503, with no alternate source. Required existing migrations are distributed registry, diagnostics, remote projects, machine capabilities and project runtime catalogue. Missing sources return explicit 503; there is no stale-data or alternate authentication fallback.

The normal central updater syncs both checkouts and runs `migrate-admin-read-api.php --execute INSTALL_ADMIN_READ_API`. It inserts one marked nginx location into the existing explicit ZeroTier HTTPS vhost, preserves all Web/agent locations, backs up the previous config under `/var/backups/devmcp-admin-read-api-*`, runs nginx -t, reloads, and restores on failure. Installation refuses an unmanaged conflicting API route. PHP-FPM is reloaded through the existing deployer.

After deployment, `bin/devmcp-admin-read-api-check --check` runs as www-data. It creates a temporary read token through the trusted server CLI, performs real verified HTTPS requests to the server's ZeroTier-bound IP (guest refusal, list/detail/project, write refusal, missing resource, revocation), and always revokes the token. It prints only statuses/IDs/counts. This tests production data/auth enforcement without reading or resetting the administrator password. Password-based issuance is tested end to end in the HTTP fixture; a real device password login remains a separate integration check. Requests to the server's own ZeroTier address do not alone prove connectivity from another peer; external checks must be reported separately.
