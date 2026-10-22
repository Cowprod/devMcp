# Enabling agent enrollment on the isolated ZeroTier admin host

Do not enable before backing up MariaDB, validating trusted TLS and testing
the endpoint against malformed input and replay. This does not enable jobs.

1. Configure a dedicated DB migration user with CREATE privilege and put
   migration_username/migration_password in /etc/devmcp-admin/distributed.php.
   Do not reuse the Web read-only or writer account for schema changes.
2. Back up the devmcp_distributed database and store the dump off-host.
3. Run with DEVMCP_DISTRIBUTED_CONFIG=/etc/devmcp-admin/distributed.php:
   php /opt/devmcp-admin/bin/devmcp-enrollment-migrate --check
   php /opt/devmcp-admin/bin/devmcp-enrollment-migrate --apply
4. Install a TLS certificate trusted by the agent and valid for the exact
   server IP (IP SAN) or change the client to use a trusted DNS hostname.
   Never use curl -k or disable peer/hostname validation.
5. Configure Nginx rate limiting in http {}:
   limit_req_zone $binary_remote_addr zone=devmcp_enroll:10m rate=2r/m;
   Then enable the commented enrollment location in the example Nginx config.
   The default Nginx configuration must remain unchanged until reviewed.
6. Run nginx -t before reload. Confirm endpoint rejects GET, oversized
   requests, expired tokens and repeated redemption. Confirm the admin
   remains Basic Auth protected and the endpoint is bound only to ZeroTier.
7. Generate invitation in admin; on the remote host use the installer with
   --server, --ca-file and --token-stdin (read token without echo). The agent service is NOT
   installed or enabled by this phase. The token is a secret: do not paste
   it into persistent shell history or shared logs. Example:\n   read -rs -p 'Enrollment token: ' TOKEN; echo\n   printf '%s\\n' \"$TOKEN\" | sudo bash install-agent.sh --server https://192.168.194.189 --ca-file /path/to/trusted-ca.pem --token-stdin\n   unset TOKEN
8. Review local policy, approved deployment binding and security gates
   before enabling the worker. Never enable the timer merely because
   enrollment succeeded.

Known constraints: The installer currently expects preinstalled PHP 8.3+,
ZeroTier and curl. It is not yet the final single-command provisioning
experience. The server's current TLS certificate is self-signed; agents must
receive its verified trust anchor through a separate trusted channel.
