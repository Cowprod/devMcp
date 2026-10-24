# Privileged devMcp certificate publication action

The agent-facing public certificate endpoint is installed through a **fixed, argument-free** privileged helper. The MCP worker never receives unrestricted root shell access.

## One-time privileged bootstrap on touchdeck

After reviewing `deploy/distributed/enable-public-cert.sh`, install an immutable root-owned copy (do not execute a helper directly from the Git workspace):

```sh
sudo install -o root -g root -m 0755 /opt/devmcp-admin/deploy/distributed/enable-public-cert.sh /usr/local/sbin/devmcp-enable-public-cert
sudo visudo -f /etc/sudoers.d/devmcp-public-cert
```

The sudoers file must contain exactly:

```text
devmcp ALL=(root) NOPASSWD: /usr/local/sbin/devmcp-enable-public-cert ""
```

Validate with `sudo visudo -cf /etc/sudoers.d/devmcp-public-cert`.

Add a single **explicit project action** to the central `devmcp-admin` project in `/etc/devmcp/global.php` (under its `actions` array):

```php
'admin.certificate.publish' => [
    'description' => 'Publish only the devMcp public TLS certificate over the ZeroTier vhost.',
    'argv' => ['/usr/bin/sudo', '-n', '/usr/local/sbin/devmcp-enable-public-cert'],
    'cwd' => '.',
    'timeout' => 30,
    'sync' => false,
],
```

The project should retain its existing `shared_actions`. Reload the devMcp worker configuration using the established production procedure, then call `admin.certificate.publish`.

The helper rejects arguments and unrecognized Nginx layout, checks the certificate, validates Nginx before reload, and restores the vhost if validation/reload fails. The public endpoint serves only `/etc/devmcp-admin/tls/admin.crt`, never the private key.

**Security:** Do not expose a general `sudo bash`, `sudo nginx`, `sudo cp`, or arbitrary-path action. Restrict modifications to the root-owned helper to privileged administrators. The one-time root bootstrap is unavoidable under the current action allowlist.
