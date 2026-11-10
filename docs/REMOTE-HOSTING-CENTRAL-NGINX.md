# TouchDeck central: two independent hosting HTTPS endpoints

The central SQL enrollment, `remote_hosting_queue_enabled=true`, and the
three caseRouter deployment grants do **not** automatically create the HTTP
routes used by the separately opted-in hosting agent. A working
`/v1/agent/ping` or legacy job queue is not proof that the following two
exact URLs are routed:

- `POST /v1/agent/hosting-poll` -> `public/agent-hosting-poll.php`
- `POST /v1/agent/hosting-result` -> `public/agent-hosting-result.php`

The default `deploy/distributed/nginx-admin.conf.example` does **not**
publish these privileged routes. In particular, the previous lack of
`claimed_at` on all three `prepare-all` requests is consistent with
missing locations, but is **not proof** that the live Nginx vhost lacks them.

## Root-approved central-only install

After reviewing and installing the current Cowprod/devMcp code and confirming
both PHP handlers are present in `/opt/devmcp-admin/public`, the **TouchDeck**
administrator can use one explicit local command:

```bash
sudo bash deploy/distributed/enable-hosting-central-routes.sh --root-approved
```

This operation is **not** an agent action and cannot be launched from a
remote read-only job. The script refuses to proceed unless:

1. The central Nginx vhost is a regular root:root 0644 file, has a single
   literal IPv4 TLS listener on a local `zt*` device, and already routes
   `/v1/agent/ping`.
2. The central PHP configuration is a root-owned, single-link regular
   file (1–65536 bytes) without group/other write bits, under root-owned,
   non-group/other-writable `/etc` and `/etc/devmcp-admin` directories.
   This check is required because the installer evaluates the PHP config as
   root. The existing writer must be configured, and
   `remote_hosting_queue_enabled` must be strictly `true`.
3. Both PHP handlers exist and pass PHP syntax checking, PHP 8.3 FPM is
   available, and the existing Nginx configuration passes `nginx -t`.
4. The renderer recognizes exactly one controlled vhost and rejects any
   preexisting conflicting hosting location. It is byte-idempotent.
5. The updated vhost passes `nginx -t` before Nginx reload. On a failure
   after replacement, the original vhost is atomically restored and reload
   is retried. The installer does not change the listener or Nginx root.
6. A root-owned exclusive `/run/lock/devmcp-hosting-central-nginx.lock`
   prevents concurrent installer invocations across atomic file renames.
   Symlinks and unexpected lock ownership/modes are rejected. The installer
   also checks that the vhost still matches its saved baseline immediately
   before replacement, refusing a detected concurrent manual edit. This is
   an advisory lock: manual Nginx edits must still be coordinated by the
   operator. No additional service or privileged agent action is installed.

The two locations disable Basic Auth only for machine-scoped bearer
authentication and remain restricted to POST, bounded bodies, TLS/ZeroTier,
the central root flag and the existing database checks. They do not install
any helper or grant on caseRouter and do not start any application. The
installer does not touch WAN/LAN, routes, NAT, firewall, or router services.

The test `tests/distributed/nginx-hosting-routes-smoke.sh` exercises the
pure renderer's idempotence and fail-closed conditions without modifying a
live host. CI success does **not** attest that the actual Nginx vhost has
been updated.

## Next field evidence

After a central installation is **explicitly verified**, check the current
agent hosting opt-in and root socket on caseRouter using already approved
mechanisms. Only then enqueue fresh short-lived `prepare-all` requests
under central root authorization, inspect `claimed_at` / receipts, and
activate each app under its own nonce. An independent ZeroTier peer must
verify static HTTP 17601, PHP HTTP 17602 and Node HTTP/WebSocket 17603;
the root executor must verify the HMAC proof before `commit-initial`.
Do not equate an HTTP 200 from the central queue with a deployed app.

**Current verified deployment count: 0/3.** No router installation or
real-world HTTP/WebSocket validation has been performed by this change.
