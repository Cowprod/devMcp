<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Project;

use Cowprod\DevMcp\Artifact\ArtifactService;
use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Distributed\Admin\ProvisioningProjectProjection;
use Cowprod\DevMcp\Execution\ActionRunner;
use Cowprod\DevMcp\Execution\FileJobStore;
use Cowprod\DevMcp\Execution\JobManager;
use Cowprod\DevMcp\GitHub\PrivateRepositoryCreator;
use Cowprod\DevMcp\Mcp\HostTools;
use Cowprod\DevMcp\Mcp\ProjectTools;
use Cowprod\DevMcp\Mcp\RemoteTools;
use Cowprod\DevMcp\Project\LocalWorkspaceProvisioner;
use Cowprod\DevMcp\Project\ProjectRegistry;
use Cowprod\DevMcp\Project\SqlProjectConfiguration;
use Cowprod\DevMcp\Project\ProvisioningStore;
use Cowprod\DevMcp\Project\ProvisioningState;
use Cowprod\DevMcp\Project\ProjectCreationPreview;
use PDO;
use PDOException;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * E2E in a disposable local environment: GitHub HTTPS is mocked and
 * github.com SSH clone URLs are rewritten to a local bare git fixture.
 * No external repository, agent, or real project is touched.
 */
final class ProjectCreationEndToEndTest extends TestCase
{
    private string $sandbox = '';
    private array $previousGitConfig = [];
    private array $previousPhpEnv = [];
    private PDO $db;
    private ProjectTools $tools;
    private ProvisioningStore $store;
    private int $githubCalls = 0;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite not installed');
        }
        $git = new Process(['git', '--version']);
        $git->run();
        if (!$git->isSuccessful()) {
            self::markTestSkipped('git executable not available');
        }

        $this->sandbox = sys_get_temp_dir() . '/devmcp-create-e2e-' . bin2hex(random_bytes(8));
        mkdir($this->sandbox, 0700);
        mkdir($this->sandbox . '/upstream', 0700);
        mkdir($this->sandbox . '/workspaces', 0700);

        $bare = new Process(['git', 'init', '--bare', $this->sandbox . '/upstream/bakery-e2e.git']);
        $bare->mustRun();

        $tokenFile = $this->sandbox . '/token';
        file_put_contents($tokenFile, 'github_pat_' . str_repeat('x', 40) . "\n");
        chmod($tokenFile, 0600);

        // The very same LocalWorkspaceProvisioner Git command runs, but
        // never resolves github.com and cannot mutate a real repository.
        foreach (['GIT_CONFIG_COUNT', 'GIT_CONFIG_KEY_0', 'GIT_CONFIG_VALUE_0', 'GIT_ALLOW_PROTOCOL'] as $name) {
            $value = getenv($name);
            $this->previousGitConfig[$name] = $value === false ? null : $value;
            $this->previousPhpEnv[$name] = array_key_exists($name, $_ENV)
                ? ['present' => true, 'value' => $_ENV[$name]] : ['present' => false];
        }
        putenv('GIT_CONFIG_COUNT=1');
        putenv('GIT_CONFIG_KEY_0=url.file://' . $this->sandbox . '/upstream/.insteadOf');
        putenv('GIT_CONFIG_VALUE_0=git@github.com:Cowprod/');
        putenv('GIT_ALLOW_PROTOCOL=file');
        // Symfony Process composes subprocess environments from $_ENV and
        // $_SERVER as well as getenv(). Putenv() alone is insufficient in CI.
        $_ENV['GIT_CONFIG_COUNT'] = '1';
        $_ENV['GIT_CONFIG_KEY_0'] = 'url.file://' . $this->sandbox . '/upstream/.insteadOf';
        $_ENV['GIT_CONFIG_VALUE_0'] = 'git@github.com:Cowprod/';
        $_ENV['GIT_ALLOW_PROTOCOL'] = 'file';

        $this->db = new PDO('sqlite::memory:');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('CREATE TABLE dm_project (id TEXT PRIMARY KEY, description TEXT NOT NULL, repository_url TEXT)');
        $this->db->exec('CREATE TABLE dm_project_provisioning ('
            . 'project_id TEXT PRIMARY KEY, description TEXT NOT NULL, repository TEXT UNIQUE NOT NULL, '
            . 'repository_mode TEXT NOT NULL, state TEXT NOT NULL, repository_confirmed INTEGER NOT NULL DEFAULT 0, '
            . 'machine TEXT, workspace_path TEXT, actions_enabled INTEGER NOT NULL DEFAULT 0, revision INTEGER NOT NULL DEFAULT 1)');
        $this->db->exec('CREATE TABLE dm_machine (id TEXT PRIMARY KEY, name TEXT NOT NULL, status TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE dm_deployment (id TEXT PRIMARY KEY, project_id TEXT NOT NULL, '
            . 'machine_id TEXT NOT NULL, workspace_path TEXT, enabled INTEGER NOT NULL DEFAULT 0)');

        $this->db->exec('CREATE TABLE dm_shared_action_config (action_id TEXT PRIMARY KEY, config_json TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE dm_local_project_config (project_id TEXT PRIMARY KEY, config_json TEXT NOT NULL)');
        $this->db->exec('CREATE TABLE dm_project_registry_state (id INTEGER PRIMARY KEY, ready INTEGER NOT NULL)');
        $this->db->exec('INSERT INTO dm_project_registry_state (id,ready) VALUES (1,1)');
        $templates = require dirname(__DIR__, 2) . '/config/shared-actions.php';
        $insert = $this->db->prepare(
            'INSERT INTO dm_shared_action_config (action_id,config_json) VALUES (?,?)'
        );
        foreach (SqlProjectConfiguration::DEFAULT_ACTIONS as $name) {
            $insert->execute([$name, json_encode($templates[$name], JSON_THROW_ON_ERROR)]);
        }
        $registry = ProjectRegistry::fromSql(new SqlProjectConfiguration($this->db));
        $audit = new AuditLogger($this->sandbox . '/audit.jsonl');
        $jobs = new JobManager($registry, new FileJobStore($this->sandbox . '/jobs'), new ArtifactService(), $audit);
        $creator = new PrivateRepositoryCreator(
            'Cowprod',
            $tokenFile,
            function (string $url, string $token, array $payload): array {
                $this->githubCalls++;
                self::assertSame('https://api.github.com/orgs/Cowprod/repos', $url);
                self::assertStringStartsWith('github_pat_', $token);
                self::assertTrue($payload['private']);
                self::assertSame('private', $payload['visibility']);
                self::assertTrue($payload['auto_init']);
                return [
                    'status' => 201,
                    'body' => json_encode([
                        'id' => 1234,
                        'name' => $payload['name'],
                        'private' => true,
                        'visibility' => 'private',
                        'owner' => ['login' => 'Cowprod'],
                    ], JSON_THROW_ON_ERROR),
                ];
            }
        );
        $this->store = new ProvisioningStore($this->db);
        $this->tools = new ProjectTools(
            $registry,
            new ActionRunner($audit),
            $jobs,
            $audit,
            new HostTools(),
            new RemoteTools($this->db),
            $creator,
            $this->store,
            new LocalWorkspaceProvisioner($this->sandbox . '/workspaces')
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->previousGitConfig as $name => $value) {
            putenv($value === null ? $name : $name . '=' . $value);
            $previous = $this->previousPhpEnv[$name] ?? ['present' => false];
            if ($previous['present']) {
                $_ENV[$name] = $previous['value'];
            } else {
                unset($_ENV[$name]);
            }
        }
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->sandbox, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $file) {
                if ($file->isDir() && !$file->isLink()) {
                    rmdir($file->getPathname());
                } else {
                    unlink($file->getPathname());
                }
            }
            rmdir($this->sandbox);
        }
    }

    public function testCreatesPrivateRepositoryIntentAndWorkspaceVisibleInBothCatalogues(): void
    {
        // Preflight only the temporary file URL, with network protocols denied.
        $probe = new Process(['git', 'ls-remote', 'git@github.com:Cowprod/bakery-e2e.git'],
            $this->sandbox . '/workspaces', ['GIT_ALLOW_PROTOCOL' => 'file']);
        $probe->run();
        self::assertTrue($probe->isSuccessful(), 'Local Git fixture unavailable: ' . substr($probe->getErrorOutput(), 0, 400));

        $result = $this->tools->projectCreate('bakery-e2e', 'Bakery orders', 'CREATE_PROJECT');

        self::assertSame(1, $this->githubCalls);
        self::assertSame('active', $result['state']);
        self::assertSame('active', $result['project']['state']);
        self::assertTrue($result['actions_authorized']);
        self::assertTrue($result['github']['private']);
        self::assertTrue($result['github']['project_registered']);
        self::assertTrue($result['github']['workspace_created']);
        self::assertTrue($result['github']['actions_authorized']);
        self::assertSame('Cowprod/bakery-e2e', $result['github']['repository']);

        $workspace = $this->sandbox . '/workspaces/bakery-e2e';
        self::assertDirectoryExists($workspace . '/.git');
        self::assertSame($workspace, $result['project']['workspace_path']);
        self::assertSame('active', $this->store->get('bakery-e2e')['state']);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM dm_deployment')->fetchColumn());

        $mcpCatalogue = $this->tools->projectList()['projects'];
        $entry = array_values(array_filter($mcpCatalogue, static fn(array $row): bool => $row['id'] === 'bakery-e2e'));
        self::assertCount(1, $entry);
        self::assertSame('active', $entry[0]['provisioning_state']);
        self::assertSame(count(SqlProjectConfiguration::DEFAULT_ACTIONS), $entry[0]['action_count']);
        self::assertSame($workspace, $entry[0]['workspace']);
        self::assertCount(
            count(SqlProjectConfiguration::DEFAULT_ACTIONS),
            $this->tools->actionList('bakery-e2e')['actions']
        );
        // The same long-lived MCP runtime must execute a fresh project's action.
        self::assertTrue($this->tools->actionRun('bakery-e2e', 'git.status')['ok']);

        $webCatalogue = ProvisioningProjectProjection::merge([], $this->store->list());
        self::assertSame('active', $webCatalogue['bakery-e2e']['provisioning_state']);
        self::assertSame($workspace, $webCatalogue['bakery-e2e']['workspace']);
        self::assertSame([], $webCatalogue['bakery-e2e']['actions']);
        self::assertSame('https://github.com/Cowprod/bakery-e2e', $webCatalogue['bakery-e2e']['repository_url']);
    }

    public function testReassignmentRevokesPreviouslyActiveSqlActions(): void
    {
        $this->tools->projectCreate('bakery-e2e', 'Bakery orders', 'CREATE_PROJECT');
        self::assertCount(
            count(SqlProjectConfiguration::DEFAULT_ACTIONS),
            $this->tools->actionList('bakery-e2e')['actions']
        );

        $record = $this->store->get('bakery-e2e');
        $updated = $this->store->advance(
            'bakery-e2e', $record['revision'], 'machine_assigned',
            ['machine' => 'other-host']
        );
        self::assertFalse($updated['actions_enabled']);
        self::assertSame([], $this->tools->actionList('bakery-e2e')['actions']);
        self::assertSame(0, (int) $this->db->query(
            "SELECT COUNT(*) FROM dm_local_project_config WHERE project_id='bakery-e2e'"
        )->fetchColumn());
        $this->expectException(InvalidArgumentException::class);
        $this->tools->actionRun('bakery-e2e', 'git.status');
    }

    public function testMissingGitOriginLeavesVisibleInactiveProjectWithoutActionGrant(): void
    {
        // No bare Git fixture exists for this repository: the non-network clone
        // fails after simulated GitHub creation and central registration.
        try {
            $this->tools->projectCreate('bakery-no-origin', 'Missing Git origin', 'CREATE_PROJECT');
            self::fail('A failed clone must not be reported as a created workspace');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Git clone failed', $exception->getMessage());
        }
        self::assertSame(1, $this->githubCalls);
        self::assertSame('registered', $this->store->get('bakery-no-origin')['state']);
        self::assertFalse($this->store->get('bakery-no-origin')['actions_enabled']);
        self::assertSame([], $this->tools->actionList('bakery-no-origin')['actions']);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM dm_deployment')->fetchColumn());

        $webCatalogue = ProvisioningProjectProjection::merge([], $this->store->list());
        self::assertSame('registered', $webCatalogue['bakery-no-origin']['provisioning_state']);
        self::assertSame([], $webCatalogue['bakery-no-origin']['actions']);
    }
    public function testOccupiedWorkspaceRejectsBeforeGitHubCreation(): void
    {
        $path = $this->sandbox . '/workspaces/bakery-e2e';
        mkdir($path, 0700);
        try {
            $this->tools->projectCreate('bakery-e2e', 'Existing workspace', 'CREATE_PROJECT');
            self::fail('An occupied local target must reject creation');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Workspace already exists', $exception->getMessage());
        }
        self::assertSame(0, $this->githubCalls);
        self::assertDirectoryExists($path);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM dm_project')->fetchColumn());
    }

    public function testReservedRepositoryRejectsBeforeGitHubCreation(): void
    {
        $reserved = ProvisioningState::begin(ProjectCreationPreview::prepare(
            'previous-intent', 'Existing reservation', 'Cowprod/bakery-e2e',
            'create', 'local', 'local', '/srv/devmcp/workspaces/previous-intent'
        ));
        $this->store->create($reserved);
        try {
            $this->tools->projectCreate('bakery-e2e', 'New project', 'CREATE_PROJECT');
            self::fail('A reserved repository must reject creation');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('reserve', $exception->getMessage());
        }
        self::assertSame(0, $this->githubCalls);
        self::assertSame('planned', $this->store->get('previous-intent')['state']);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM dm_project')->fetchColumn());
    }

    public function testReservedProjectIdRejectsBeforeGitHubCreation(): void
    {
        $reserved = ProvisioningState::begin(ProjectCreationPreview::prepare(
            'bakery-e2e', 'Existing intent', 'Cowprod/old-repository',
            'attach', 'local', 'local', '/srv/devmcp/workspaces/bakery-e2e'
        ));
        $this->store->create($reserved);
        try {
            $this->tools->projectCreate('bakery-e2e', 'New project', 'CREATE_PROJECT');
            self::fail('A reserved project ID must reject creation');
        } catch (InvalidArgumentException $exception) {
            self::assertStringContainsString('reserve', $exception->getMessage());
        }
        self::assertSame(0, $this->githubCalls);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM dm_project')->fetchColumn());
    }

    public function testMissingProvisioningMigrationPreservesMcpProjectDiscovery(): void
    {
        $this->db->exec('DROP TABLE dm_project_provisioning');

        $catalogue = $this->tools->projectList();
        self::assertArrayHasKey('projects', $catalogue);
        self::assertArrayHasKey('host', $catalogue);
        self::assertIsArray($catalogue['projects']);
        self::assertSame(
            ['projects' => [], 'available' => false],
            $this->tools->projectProvisioningList()
        );
        self::assertSame(0, $this->githubCalls);
    }

    public function testMissingProvisioningMigrationFailsBeforeGitHubCreation(): void
    {
        $this->db->exec('DROP TABLE dm_project_provisioning');
        try {
            $this->tools->projectCreate('bakery-e2e', 'Missing journal', 'CREATE_PROJECT');
            self::fail('A missing journal must reject creation');
        } catch (PDOException $exception) {
            self::assertStringContainsString('dm_project_provisioning', $exception->getMessage());
        }
        self::assertSame(0, $this->githubCalls);
        self::assertSame(0, (int) $this->db->query('SELECT COUNT(*) FROM dm_project')->fetchColumn());
    }
}
