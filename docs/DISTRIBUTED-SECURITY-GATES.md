# Distributed execution: mandatory release gates

The experimental code in this branch MUST NOT be deployed or exposed to the
network until all gates below are met. Passing unit tests is insufficient.

1. **Policy binding:** server claims must include project, exact deployment,
   approved policy digest and revision; the agent must check all against its
   locally pinned policy and workspace. Unknown revisions fail closed.
2. **Agent identity:** HTTPS transport with trusted CA, individual revocable
   credentials, request rate limits and machine-scoped authorization.
3. **Execution:** per-deployment cross-process lock, bounded logs, explicit
   cancellation, heartbeat while running, timeouts, safe recovery on crashes.
4. **Delivery:** no automatic replay after lease loss; stale fencing tokens
   cannot mutate job state; result delivery must be idempotent.
5. **Migrations:** verify MySQL and MariaDB versions, forward-only migration
   runner, backup and rollback plan. Never auto-migrate at MCP startup.
6. **Administration:** independent authentication, CSRF protection, audit,
   explicit approvals and separation of read/write/hardware capabilities.
7. **Testing:** SQL concurrency tests, agent crash/network failure tests,
   malicious payload tests, end-to-end tests on isolated hosts.
8. **Deployment:** staged canary; preserve existing local actions and worker.
   Never flash or trigger OTA installation as part of distributed rollout.

Current AgentWorker is a prototype and intentionally not wired to production:
the server claim response does not yet carry an approved policy binding.
