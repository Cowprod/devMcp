<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Distributed\Admin;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Explicit central DBA migration for the separate hosting queue schema.
 *
 * The caller must already be root, hold dedicated migration credentials,
 * confirm a database backup and independently opt in the central queue.
 * No HTTP/MCP/agent action invokes this class. A crashed 006->007 upgrade
 * can be resumed, but unexpected existing schemas are always rejected.
 */
final class HostingLabSchemaMigration
{
    private const LEGACY = "enum('prepare-all','activate-initial','abort-initial')";
    private const CURRENT = "enum('prepare-all','activate-initial','abort-initial','commit-initial')";
    private const LOCK_NAME = 'devmcp.hosting.schema.006.007';
    private const COLUMNS = [
        'id' => 'char',
        'deployment_id' => 'char',
        'machine_id' => 'char',
        'action_name' => 'enum',
        'request_json' => 'text',
        'state' => 'enum',
        'created_at' => 'datetime',
        'expires_at' => 'datetime',
        'claimed_at' => 'datetime',
        'finished_at' => 'datetime',
        'result_json' => 'text',
    ];

    /**
     * Read-only schema inspection. It neither creates tables nor grants jobs.
     * @return 'missing'|'006_only'|'ready'
     */
    public static function state(PDO $db): string
    {
        $table = $db->query(
            "SELECT ENGINE FROM information_schema.TABLES "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dm_remote_hosting_job'"
        )->fetch(PDO::FETCH_ASSOC);
        if ($table === false) {
            return 'missing';
        }
        if (!is_array($table) || strtoupper((string) ($table['ENGINE'] ?? '')) !== 'INNODB') {
            throw new RuntimeException('Unexpected hosting queue storage engine');
        }
        $stmt = $db->query(
            "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS "
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dm_remote_hosting_job'"
        );
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $columns[$row['COLUMN_NAME']] = $row;
        }
        if (count($columns) !== count(self::COLUMNS)) {
            throw new RuntimeException('Unexpected hosting queue column inventory');
        }
        foreach (self::COLUMNS as $name => $type) {
            if (!isset($columns[$name]) || $columns[$name]['DATA_TYPE'] !== $type) {
                throw new RuntimeException('Hosting queue schema differs from pinned contract');
            }
        }
        $kind = (string) $columns['action_name']['COLUMN_TYPE'];
        if ($kind === self::CURRENT) {
            return 'ready';
        }
        if ($kind === self::LEGACY) {
            return '006_only';
        }
        throw new RuntimeException('Hosting queue action allowlist has drifted');
    }

    /**
     * Only call with a dedicated migration database identity and explicit
     * operator approval. DDL is intentionally forward-only, not transactional.
     * @return array{previous_state:string,final_state:string,migrations_applied:list<string>}
     */
    public static function apply(PDO $db): array
    {
        $claim = $db->prepare('SELECT GET_LOCK(?, 5)');
        $claim->execute([self::LOCK_NAME]);
        if ((int) $claim->fetchColumn() !== 1) {
            throw new RuntimeException('Hosting queue schema migration already locked');
        }
        try {
            $before = self::state($db);
            $state = $before;
            $applied = [];
            if ($state === 'missing') {
                self::migration($db, '006_remote_hosting_jobs.sql');
                $applied[] = '006';
                $state = self::state($db);
                if ($state !== '006_only') {
                    throw new RuntimeException('Hosting schema 006 did not create expected table');
                }
            }
            if ($state === '006_only') {
                self::migration($db, '007_remote_hosting_signed_commit.sql');
                $applied[] = '007';
            }
            if (self::state($db) !== 'ready') {
                throw new RuntimeException('Hosting schema 007 not verified after migration');
            }
            return [
                'previous_state' => $before,
                'final_state' => 'ready',
                'migrations_applied' => $applied,
            ];
        } finally {
            // Release even after failed DDL. The state reader above refuses
            // anything beyond exact 006/007 so a retry never guesses.
            try {
                $release = $db->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([self::LOCK_NAME]);
            } catch (Throwable) {
                // Connection teardown also releases a named MySQL lock.
            }
        }
    }

    private static function migration(PDO $db, string $name): void
    {
        if (!in_array($name, [
            '006_remote_hosting_jobs.sql',
            '007_remote_hosting_signed_commit.sql',
        ], true)) {
            throw new RuntimeException('Unapproved hosting migration');
        }
        $path = dirname(__DIR__, 3) . '/database/migrations/' . $name;
        $sql = @file_get_contents($path);
        if (!is_string($sql) || $sql === '' || strlen($sql) > 8192
            || @is_link($path)) {
            throw new RuntimeException('Pinned hosting migration source unavailable');
        }
        $db->exec($sql);
    }
}
