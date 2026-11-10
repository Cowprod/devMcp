<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Admin;

use RuntimeException;

/** Produce the install command for the exact deployed admin revision. */
final class AgentInstallation
{
    public static function revision(string $checkout): string
    {
        $head = trim((string) @file_get_contents($checkout . '/.git/HEAD'));
        if (str_starts_with($head, 'ref: ')) {
            $ref = substr($head, 5);
            if (!preg_match('~^refs/heads/[A-Za-z0-9._/-]+$~D', $ref) || str_contains($ref, '..')) {
                throw new RuntimeException('Deployed admin Git reference is invalid');
            }
            $resolved = trim((string) @file_get_contents($checkout . '/.git/' . $ref));
            if ($resolved === '') {
                $packed = (string) @file_get_contents($checkout . '/.git/packed-refs');
                foreach (explode("\n", $packed) as $line) {
                    if (preg_match('/^([a-f0-9]{40}) ' . preg_quote($ref, '/') . '$/D', $line, $match)) {
                        $resolved = $match[1];
                        break;
                    }
                }
            }
            $head = $resolved;
        }
        if (preg_match('/^[a-f0-9]{40}$/D', $head) !== 1 || $head === str_repeat('0', 40)) {
            throw new RuntimeException('Deployed admin revision is unavailable; installation command cannot be generated');
        }
        return $head;
    }

    public static function command(string $revision, string $server, string $certificateHash, string $token): string
    {
        if (preg_match('/^[a-f0-9]{40}$/D', $revision) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $certificateHash) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1
            || preg_match('~^https://(?:[0-9]{1,3}\.){3}[0-9]{1,3}(?::[0-9]+)?$~D', $server) !== 1) {
            throw new RuntimeException('Agent install command inputs are invalid');
        }
        $url = 'https://raw.githubusercontent.com/Cowprod/devMcp/' . $revision . '/deploy/distributed/install-agent.sh';
        return 'sudo -v && { tmp=$(mktemp) && curl --proto \'=https\' --tlsv1.2 --fail --silent --show-error --location '
            . escapeshellarg($url) . ' -o "$tmp" && { printf \'%s\\n\' ' . escapeshellarg($token)
            . ' | sudo -n bash "$tmp" --server ' . escapeshellarg($server)
            . ' --cert-url ' . escapeshellarg($server . '/devmcp-ca.crt')
            . ' --cert-sha256 ' . escapeshellarg($certificateHash) . ' --release ' . escapeshellarg($revision)
            . ' --token-stdin; }; rc=$?; rm -f "$tmp"; test "$rc" -eq 0; }';
    }
}
