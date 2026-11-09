<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Cowprod\DevMcp\Distributed\Admin\HostingLabSchemaMigration;
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;

$db = DatabaseConnection::connect(
    (string) getenv('DEVMCP_TEST_MYSQL_DSN'),
    (string) getenv('DEVMCP_TEST_MYSQL_USER'),
    (string) getenv('DEVMCP_TEST_MYSQL_PASSWORD')
);
if (HostingLabSchemaMigration::state($db) !== 'missing') {
    throw new RuntimeException('Dedicated test DB must start without hosting queue');
}
$created = HostingLabSchemaMigration::apply($db);
assert($created['previous_state'] === 'missing');
assert($created['final_state'] === 'ready');
assert($created['migrations_applied'] === ['006', '007']);
assert(HostingLabSchemaMigration::state($db) === 'ready');
assert(HostingLabSchemaMigration::apply($db)['migrations_applied'] === []);

// Existing 006-only production installs must upgrade without recreating
// tables, losing jobs, or changing any other registry data.
$db->exec('DROP TABLE dm_remote_hosting_job');
$legacy = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/006_remote_hosting_jobs.sql');
assert(is_string($legacy) && $legacy !== '');
$db->exec($legacy);
assert(HostingLabSchemaMigration::state($db) === '006_only');
$upgraded = HostingLabSchemaMigration::apply($db);
assert($upgraded['previous_state'] === '006_only');
assert($upgraded['migrations_applied'] === ['007']);
assert(HostingLabSchemaMigration::state($db) === 'ready');
assert(HostingLabSchemaMigration::apply($db)['migrations_applied'] === []);

// A foreign schema cannot be silently altered into a privileged queue.
$db->exec(
    "ALTER TABLE dm_remote_hosting_job MODIFY COLUMN action_name "
    . "ENUM('foreign-action') NOT NULL"
);
try {
    HostingLabSchemaMigration::apply($db);
    throw new RuntimeException('Unexpected hosting action schema accepted');
} catch (RuntimeException $expected) {
    if (!str_contains($expected->getMessage(), 'drifted')) {
        throw $expected;
    }
}
// Restore known exact 007 schema for the following MySQL integration tests.
$fixed = file_get_contents(dirname(__DIR__, 2) . '/database/migrations/007_remote_hosting_signed_commit.sql');
assert(is_string($fixed) && $fixed !== '');
$db->exec($fixed);
assert(HostingLabSchemaMigration::state($db) === 'ready');
echo "PASS: dedicated hosting schema missing/006/007 migration paths and foreign ENUM refusal\n";
