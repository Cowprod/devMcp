# Distributed agent deployment (experimental, NOT production-ready)

The distributed API is disabled unless DEVMCP_DISTRIBUTED_CONFIG points
to an explicit PHP configuration file returning dsn, username and password.
Configure the HTTPS web server to route only POST /v1/agent/{claim,start,
heartbeat,finish} to public/agent.php, preserving REQUEST_URI. Do not
trust client-supplied X-Forwarded-Proto; PHP must see HTTPS=on from a
trusted server configuration. Restrict access to the ZeroTier interface,
and add request rate limits and access logging with credential redaction.

Agent: install PHP 8.3, curl, Composer dependencies, a dedicated unprivileged
devmcp-agent user and a writable private /var/lib/devmcp-agent directory.
Copy config/agent.example.php to /etc/devmcp/agent.php (0600), supply a
unique machine identity and token in /etc/devmcp/agent-token (0600).
The trusted policy PHP file must return a registry configuration and the
approved SHA-256. Keep it writable only by a trusted administrator.
Copy deploy/systemd/devmcp-agent.service and devmcp-agent.timer to the
systemd unit directory, but DO NOT enable until all security gates pass.

Current restrictions: the worker uses a single host-wide flock; it does
not yet enforce per-deployment locks across different worker processes.
It does not yet validate local action revision against the server's
revision, nor bind each job to a locally approved deployment ID.
The HTTP endpoint does not yet implement application-level rate limiting.
A lost finish acknowledgement remains uncertain, not retried.
No physical two-machine end-to-end validation has been performed.

The old local worker and its actions are unchanged. No OTA installation,
firmware flashing or production deployment is authorized by this work.

## Result reconciliation

Run `bin/devmcp-agent-recovery` to list pending local attempts.
For a journal in `result_pending`, an operator may run
`bin/devmcp-agent-report <attempt-id>`. This sends only the recorded
result to the server, without executing the action again. A missing or
expired server lease may reject the result: preserve the journal and
investigate centrally. Never reset an `uncertain` journal automatically.
