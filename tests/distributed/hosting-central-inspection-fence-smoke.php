<?php

declare(strict_types=1);

// Driver-independent, read-only PDO test double. No MySQL, network,
// privileged socket or application deployment is needed.
require_once dirname(__DIR__, 2) . '/src/Distributed/Admin/HostingLabSchemaMigration.php';
require_once dirname(__DIR__, 2) . '/src/Distributed/Admin/HostingLabCentralInspection.php';

use Cowprod\DevMcp\Distributed\Admin\HostingLabCentralInspection;

final class HostingInspectionFakeStatement extends PDOStatement
{
    /** @var list<mixed> */
    private array $rows;
    private int $offset = 0;

    /** @param list<mixed> $rows */
    public function __construct(array $rows)
    {
        $this->rows = $rows;
    }

    public function execute(?array $params = null): bool
    {
        $this->offset = 0;
        return true;
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        return $this->rows[$this->offset++] ?? false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch();
        return is_array($row) ? (array_values($row)[$column] ?? false) : $row;
    }
}

final class HostingInspectionFakePDO extends PDO
{
    /** @var array<string,bool> */
    public array $pending = [];
    /** @var array<string,bool> */
    public array $activated = [];
    /** @var array<string,array{revision:string,state?:string,raw?:string}> */
    public array $preparations = [];
    public int $writes = 0;

    public function __construct()
    {
        // PDO intentionally not initialized: methods below are read-only fakes.
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if (str_contains($query, 'information_schema.TABLES')) {
            return new HostingInspectionFakeStatement([['ENGINE' => 'InnoDB']]);
        }
        if (str_contains($query, 'information_schema.COLUMNS')) {
            $types = [
                'id' => 'char', 'deployment_id' => 'char', 'machine_id' => 'char',
                'action_name' => 'enum', 'request_json' => 'text',
                'state' => 'enum', 'created_at' => 'datetime',
                'expires_at' => 'datetime', 'claimed_at' => 'datetime',
                'finished_at' => 'datetime', 'result_json' => 'text',
            ];
            $rows = [];
            foreach ($types as $name => $type) {
                $rows[] = [
                    'COLUMN_NAME' => $name, 'DATA_TYPE' => $type,
                    'COLUMN_TYPE' => $name === 'action_name'
                        ? "enum('prepare-all','activate-initial','abort-initial','commit-initial')"
                        : $type,
                ];
            }
            return new HostingInspectionFakeStatement($rows);
        }
        throw new RuntimeException('Unexpected inspection query');
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'FROM dm_machine')) {
            return new HostingInspectionFakeStatement([[
                'id' => str_repeat('f', 32), 'name' => 'caseRouter', 'status' => 'active',
            ]]);
        }
        if (str_contains($query, 'FROM dm_agent_identity')) {
            return new HostingInspectionFakeStatement([['id' => str_repeat('e', 32)]]);
        }
        if (str_contains($query, 'FROM dm_deployment d')) {
            return new HostingInspectionFakeDeploymentStatement();
        }
        if (str_contains($query, "action_name = 'prepare-all'")) {
            if (!str_contains($query, 'ORDER BY created_at DESC, id DESC LIMIT 1')) {
                throw new RuntimeException('Activation inspection must use latest prepare');
            }
            return new HostingInspectionFakePrepareStatement($this);
        }
        if (str_contains($query, 'ORDER BY created_at DESC')) {
            // Deliberately report a completed latest job even when an older
            // claimed operation remains unresolved in the same deployment.
            return new HostingInspectionFakeStatement([[
                'action_name' => 'prepare-all', 'state' => 'succeeded',
                'created_at' => '2026-11-10 00:00:00', 'expires_at' => '2026-11-10 00:10:00',
                'claimed_at' => '2026-11-10 00:00:05',
                'finished_at' => '2026-11-10 00:01:00', 'deadline_elapsed' => 1,
            ]]);
        }
        if (str_contains($query, "state = 'claimed'")) {
            if (!str_contains($query, "state = 'queued'")
                || !str_contains($query, 'expires_at > UTC_TIMESTAMP(6)')) {
                throw new RuntimeException('Central pending fence drifted from writer');
            }
            return new HostingInspectionFakeFenceStatement($this->pending);
        }
        if (str_contains($query, "action_name IN ('activate-initial', 'commit-initial')")) {
            if (!str_contains($query, "state = 'succeeded'")) {
                throw new RuntimeException('Central initial activation fence drifted');
            }
            return new HostingInspectionFakeFenceStatement($this->activated);
        }
        throw new RuntimeException('Unexpected prepared inspection query');
    }

    public function exec(string $statement): int|false
    {
        ++$this->writes;
        throw new RuntimeException('Inspection attempted a SQL write');
    }
}

final class HostingInspectionFakeDeploymentStatement extends PDOStatement
{
    private string $project = '';
    public function execute(?array $params = null): bool
    {
        $this->project = (string) ($params[1] ?? '');
        return true;
    }
    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        return match ($this->project) {
            'lab-static', 'lab-php', 'lab-node' => [
                'id' => str_repeat(match ($this->project) {
                    'lab-static' => 'a', 'lab-php' => 'b', default => 'c',
                }, 32),
                'workspace_path' => '/srv/devmcp-hosting/' . $this->project,
                'enabled' => 1,
                'repository_url' => 'https://github.com/Cowprod/devMcp.git',
            ],
            default => false,
        };
    }
}

final class HostingInspectionFakeFenceStatement extends PDOStatement
{
    /** @param array<string,bool> $flags */
    public function __construct(private array $flags)
    {
    }

    private string $deploymentId = '';
    public function execute(?array $params = null): bool
    {
        $this->deploymentId = (string) ($params[0] ?? '');
        return true;
    }
    public function fetchColumn(int $column = 0): mixed
    {
        return ($this->flags[$this->deploymentId] ?? false) ? $this->deploymentId : false;
    }
}

final class HostingInspectionFakePrepareStatement extends PDOStatement
{
    private string $deploymentId = '';

    public function __construct(private HostingInspectionFakePDO $db)
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->deploymentId = (string) ($params[0] ?? '');
        return true;
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        $record = $this->db->preparations[$this->deploymentId] ?? null;
        if ($record === null) {
            return false;
        }
        $project = match ($this->deploymentId) {
            str_repeat('a', 32) => 'lab-static',
            str_repeat('b', 32) => 'lab-php',
            str_repeat('c', 32) => 'lab-node',
            default => throw new RuntimeException('Unknown test deployment'),
        };
        $request = [
            'deployment_id' => $this->deploymentId,
            'machine_id' => str_repeat('f', 32),
            'project_id' => $project,
            'revision' => $record['revision'],
        ];
        return [
            'state' => $record['state'] ?? 'succeeded',
            'request_json' => $record['raw']
                ?? json_encode($request, JSON_THROW_ON_ERROR),
        ];
    }
}

function hostingInspectionCheck(
    HostingInspectionFakePDO $db,
    bool $clear,
    array $expectedBlockers,
    ?string $expectedRuntime = null
): void {
    $result = HostingLabCentralInspection::inspect($db, 'caseRouter', true);
    if ($result['central_queue_prerequisites_met'] !== true
        || $result['central_queue_write_fences_clear'] !== $clear
        || $result['central_queue_write_fence_blockers'] !== $expectedBlockers
        || $result['deployment_complete'] !== false
        || $db->writes !== 0) {
        throw new RuntimeException('Read-only central write-fence inspection mismatch');
    }
    if ($expectedRuntime !== null
        && ($result['deployments'][$expectedRuntime]['outstanding_central_job'] ?? null) !== true
        && ($result['deployments'][$expectedRuntime]['initial_activation_already_succeeded'] ?? null) !== true) {
        throw new RuntimeException('Missing per-runtime read-only fence evidence');
    }
}

$db = new HostingInspectionFakePDO();
hostingInspectionCheck($db, true, []);
$db->pending[str_repeat('b', 32)] = true;
hostingInspectionCheck($db, false, ['outstanding_central_job_php'], 'php');
$db->pending = [];
$db->activated[str_repeat('c', 32)] = true;
hostingInspectionCheck($db, false, ['initial_activation_already_succeeded_node'], 'node');
$db->pending[str_repeat('a', 32)] = true;
hostingInspectionCheck($db, false, [
    'outstanding_central_job_static', 'initial_activation_already_succeeded_node',
]);

function hostingActivationCheck(
    HostingInspectionFakePDO $db, bool $expectedReady, ?string $revision, array $blockers
): void {
    $result = HostingLabCentralInspection::inspect($db, 'caseRouter', true);
    if (($result['central_activation_ready'] ?? null) !== $expectedReady
        || ($result['central_activation_revision'] ?? false) !== $revision
        || ($result['central_activation_blockers'] ?? null) !== $blockers
        || $db->writes !== 0 || $result['deployment_complete'] !== false) {
        throw new RuntimeException('Read-only three-demo activation readiness mismatch');
    }
}

$db->pending = [];
$db->activated = [];
hostingActivationCheck($db, false, null, [
    'latest_preparation_unconfirmed_static',
    'latest_preparation_unconfirmed_php',
    'latest_preparation_unconfirmed_node',
]);
$sha = str_repeat('1', 40);
foreach (['a', 'b', 'c'] as $id) {
    $db->preparations[str_repeat($id, 32)] = ['revision' => $sha];
}
hostingActivationCheck($db, true, $sha, []);
$db->preparations[str_repeat('b', 32)]['revision'] = str_repeat('2', 40);
hostingActivationCheck($db, false, null, ['preparation_revisions_mismatch']);
$db->preparations[str_repeat('b', 32)]['revision'] = $sha;
$db->preparations[str_repeat('c', 32)]['state'] = 'failed';
hostingActivationCheck($db, false, null, ['latest_preparation_unconfirmed_node']);
$db->preparations[str_repeat('c', 32)]['state'] = 'succeeded';
$db->preparations[str_repeat('a', 32)]['raw'] = '{"bad":';
hostingActivationCheck($db, false, null, ['latest_preparation_unconfirmed_static']);
unset($db->preparations[str_repeat('a', 32)]['raw']);
$db->pending[str_repeat('b', 32)] = true;
hostingActivationCheck($db, false, null, ['outstanding_central_job_php']);
$db->pending = [];
$db->activated[str_repeat('a', 32)] = true;
hostingActivationCheck($db, false, null, ['initial_activation_already_succeeded_static']);
echo "PASS: central lab inspection respects writer fences, revision-consistent activation readiness and invalid evidence\n";
