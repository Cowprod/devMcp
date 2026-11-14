<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;

use RuntimeException;

/** Public certificate metadata matched against usable private signing identities. */
final class MacSigningTeams
{
    public static function fromKeychain(string $identities, string $certificates, ?int $now = null): array
    {
        $now ??= time();
        preg_match_all('/^\s*\d+\)\s+([A-Fa-f0-9]{40})\s+"/m', $identities, $matches);
        $usable = array_fill_keys(array_map('strtolower', $matches[1]), true);
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $certificates, $blocks);
        $teams = [];
        foreach ($blocks[0] as $pem) {
            $fingerprint = openssl_x509_fingerprint($pem, 'sha1');
            $certificate = openssl_x509_parse($pem);
            if ($fingerprint === false || $certificate === false || !isset($usable[strtolower($fingerprint)])) continue;
            if ($certificate['validFrom_time_t'] > $now || $certificate['validTo_time_t'] <= $now) continue;
            $subject = $certificate['subject'];
            $id = $subject['OU'] ?? null;
            $name = $subject['O'] ?? null;
            $cn = $subject['CN'] ?? '';
            if (!is_string($id) || !preg_match('/^[A-Z0-9]{10}$/D', $id) || !is_string($name) || trim($name) === '') continue;
            if (!is_string($cn) || !preg_match('/^(Apple Development|Apple Distribution|iPhone Developer|iPhone Distribution): /', $cn)) continue;
            if (isset($teams[$id]) && $teams[$id]['name'] !== $name) throw new RuntimeException('Conflicting Apple team labels in signing certificates');
            $teams[$id] ??= ['id' => $id, 'name' => $name, 'signing_certificate_count' => 0];
            // Keychain search lists can return the same public certificate more than once.
            if (isset($teams[$id]['seen'][$fingerprint])) continue;
            $teams[$id]['seen'][$fingerprint] = true;
            ++$teams[$id]['signing_certificate_count'];
        }
        ksort($teams);
        foreach ($teams as &$team) unset($team['seen']);
        return array_values($teams);
    }

    public static function requireTeam(?string $id, array $teams): string
    {
        foreach ($teams as $team) if ($team['id'] === $id) return $id;
        throw new RuntimeException(json_encode([
            'code' => $id === null ? 'APPLE_TEAM_REQUIRED' : 'APPLE_TEAM_UNAVAILABLE',
            'message' => 'Choose a team_id from ios.capabilities.signing_teams, or explicitly configure the project team_id. Certificate CN identifiers are not Team IDs.',
            'available_teams' => $teams,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
