<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Mcp;

use Cowprod\DevMcp\Audit\AuditLogger;
use Cowprod\DevMcp\Execution\ActionRunner;
use Cowprod\DevMcp\Execution\JobManager;
use Cowprod\DevMcp\GitHub\PrivateRepositoryCreator;
use Cowprod\DevMcp\Project\ProjectRegistry;
use Cowprod\DevMcp\Project\ProjectCreationPreview;
use Cowprod\DevMcp\Project\ProvisioningState;
use Cowprod\DevMcp\Project\ProvisioningStore;
use Cowprod\DevMcp\Project\LocalWorkspaceProvisioner;
use InvalidArgumentException;

final class ProjectTools
{
    public function __construct(
        private readonly ProjectRegistry $projects,
        private readonly ActionRunner $runner,
        private readonly JobManager $jobs,
        private readonly AuditLogger $audit,
        private readonly HostTools $hostTools = new HostTools(),
        private readonly ?RemoteTools $remoteTools = null,
        private readonly ?PrivateRepositoryCreator $githubCreator = null,
        private readonly ?ProvisioningStore $provisioning = null,
        private readonly ?LocalWorkspaceProvisioner $localProvisioner = null,
    ) {
    }

    /**
     * @return array{projects: list<array<string, mixed>>, host: array<string, mixed>}
     */
    public function projectList(): array
    {
        $projects = [];
        foreach ($this->projects->all() as $project) {
            $projects[] = $project->toPublicArray();
        }

        if ($this->provisioning !== null) {
            foreach ($this->provisioning->list() as $entry) {
                if (isset($this->projects->all()[$entry['project_id']])) {
                    continue;
                }
                $projects[] = [
                    'id' => $entry['project_id'],
                    'description' => $entry['description'],
                    'repository' => $entry['repository'],
                    'provisioning_state' => $entry['state'],
                    'machine' => $entry['machine'],
                    'action_count' => 0,
                ];
            }
        }

        if ($this->remoteTools !== null) {
            foreach ($this->remoteTools->projects() as $remote) {
                if (!isset($this->projects->all()[$remote['id']]) && !in_array($remote['id'], array_column($projects, 'id'), true)) {
                    $projects[] = $remote;
                }
            }
        }
        return [
            'projects' => $projects,
            'host' => $this->hostTools->capabilities(),
        ];
    }

    /**
     * Register a project intent, without creating a repository or granting any action.
     * The caller must use the preview first; the database enforces uniqueness.
     */
    public function projectRegister(
        string $project_id,
        string $description,
        string $github_repository,
        string $repository_mode,
        string $confirmation,
    ): array {
        if ($confirmation !== 'REGISTER_PROJECT') {
            throw new InvalidArgumentException('Confirmation explicite requise');
        }
        if ($this->provisioning === null) {
            throw new InvalidArgumentException('Registre de provisionnement indisponible');
        }
        if ($this->remoteTools === null) {
            throw new InvalidArgumentException('Registre central requis pour enregistrer un projet');
        }
        if ($this->localProvisioner === null) {
            throw new InvalidArgumentException('Provisionnement local requis pour creer un projet');
        }
        $preview = $this->projectCreatePreview(
            $project_id, $description, $github_repository, $repository_mode,
            'local', 'local', '/srv/devmcp/workspaces/' . $project_id
        );
        // Validate both reservations and destination before any SQL write or clone.
        // The central uniqueness constraints remain the authoritative race guard.
        $this->provisioning->assertAvailableForCreate($project_id, $github_repository);
        $this->localProvisioner->assertDestinationAvailable($project_id);
        // An unassigned project must not inherit an implicit machine binding.
        $record = ProvisioningState::begin($preview);
        $this->audit->append([
            'project' => '_provisioning', 'action' => 'project.register',
            'project_id' => $project_id, 'outcome' => 'requested',
        ]);
        $created = $this->provisioning->registerCentral($record);
        $this->audit->append([
            'project' => '_provisioning', 'action' => 'project.register',
            'project_id' => $project_id, 'outcome' => 'registered_intent',
        ]);
        // Local-first: a successful registration must leave a usable workspace.
        // Clone failure leaves a registered, inactive project for explicit recovery.
        try {
            $workspace = $this->localProvisioner->provision($project_id, $github_repository);
        } catch (\Throwable $error) {
            $this->audit->append([
                'project' => '_provisioning', 'action' => 'project.workspace.provision',
                'project_id' => $project_id, 'outcome' => 'failed',
                'error_class' => get_class($error),
            ]);
            throw $error;
        }
        $hostname = gethostname();
        if (!is_string($hostname) || $hostname === '') {
            throw new InvalidArgumentException('Hostname local indisponible');
        }
        $created = $this->provisioning->advance($project_id, $created['revision'], 'repository_ready');
        $created = $this->provisioning->advance(
            $project_id, $created['revision'], 'machine_assigned', ['machine' => $hostname]
        );
        $created = $this->provisioning->advance(
            $project_id, $created['revision'], 'active', ['workspace_path' => $workspace['workspace_path']]
        );
        $this->audit->append([
            'project' => '_provisioning', 'action' => 'project.workspace.provision',
            'project_id' => $project_id, 'outcome' => 'active_local',
        ]);
        return $created;
    }

    /**
     * Single MCP operation: GitHub private repository, central registration,
     * local workspace and automatic lifecycle activation.
     * Never deletes a repository or workspace on partial failure.
     */
    public function projectCreate(string $project_id, string $description, string $confirmation): array
    {
        if ($confirmation !== 'CREATE_PROJECT') {
            throw new InvalidArgumentException('Confirmation explicite requise');
        }
        if ($this->githubCreator === null || $this->localProvisioner === null
            || $this->provisioning === null || $this->remoteTools === null) {
            throw new InvalidArgumentException('Creation integree non configuree');
        }
        $repository = $this->githubCreator->organization() . '/' . $project_id;
        // Validate all local and central preconditions before GitHub mutation.
        $this->projectCreatePreview(
            $project_id, $description, $repository, 'create',
            'local', 'local', '/srv/devmcp/workspaces/' . $project_id
        );
        // Never create a GitHub repository when the journal already reserves
        // the ID/repository or a local clone target is occupied.
        $this->provisioning->assertAvailableForCreate($project_id, $repository);
        $this->localProvisioner->assertDestinationAvailable($project_id);
        $this->audit->append([
            'project' => '_provisioning', 'action' => 'project.create',
            'project_id' => $project_id, 'outcome' => 'requested',
        ]);
        $github = $this->githubRepositoryCreate($repository, 'CREATE_PRIVATE_REPOSITORY');
        try {
            $project = $this->projectRegister(
                $project_id, $description, $repository, 'create', 'REGISTER_PROJECT'
            );
        } catch (\Throwable $error) {
            $this->audit->append([
                'project' => '_provisioning', 'action' => 'project.create',
                'project_id' => $project_id, 'outcome' => 'partial_failure',
                'repository' => $repository, 'error_class' => get_class($error),
            ]);
            throw $error;
        }
        return [
            'project' => $project,
            'github' => $github,
            'state' => 'active',
            'actions_authorized' => false,
        ];
    }

    public function projectRepositoryConfirm(string $project_id, string $repository, int $revision, string $confirmation): array
    {
        if ($confirmation !== 'CONFIRM_REPOSITORY_READY') {
            throw new InvalidArgumentException('Confirmation explicite requise');
        }
        if ($this->provisioning === null) {
            throw new InvalidArgumentException('Registre de provisionnement indisponible');
        }
        $current = $this->provisioning->get($project_id);
        if ($current['state'] !== 'registered') {
            throw new InvalidArgumentException('Projet non enregistre ou depot deja confirme');
        }
        if ($current['repository'] !== $repository) {
            throw new InvalidArgumentException('Depot different de celui du projet');
        }
        $updated = $this->provisioning->advance($project_id, $revision, 'repository_ready');
        $this->audit->append([
            'project' => '_provisioning', 'action' => 'project.repository.confirm',
            'project_id' => $project_id, 'repository' => $repository, 'outcome' => 'recorded',
        ]);
        return $updated;
    }

    public function projectAssignMachine(string $project_id, string $machine, int $revision, string $confirmation): array
    {
        if ($confirmation !== 'ASSIGN_MACHINE' || $this->provisioning === null || $this->remoteTools === null) {
            throw new InvalidArgumentException('Affectation indisponible ou non confirmee');
        }
        if ($machine === 'local') {
            if ($this->localProvisioner === null) {
                throw new InvalidArgumentException('Provisionnement local non configure');
            }
            $hostname = gethostname();
            if (!is_string($hostname) || $hostname === '') {
                throw new InvalidArgumentException('Hostname local indisponible');
            }
            $updated = $this->provisioning->advance(
                $project_id, $revision, 'machine_assigned', ['machine' => $hostname]
            );
            $this->audit->append([
                'project' => '_provisioning', 'action' => 'project.machine.assign',
                'project_id' => $project_id, 'machine_id' => $hostname, 'outcome' => 'assigned',
            ]);
            return $updated;
        }
        $resolved = $this->remoteTools->machineForProvisioningPreview($machine);
        if ($resolved['status'] !== 'active') {
            throw new InvalidArgumentException('Machine non active');
        }
        $updated = $this->provisioning->advance(
            $project_id, $revision, 'machine_assigned', ['machine' => $resolved['id']]
        );
        $this->audit->append([
            'project' => '_provisioning', 'action' => 'project.machine.assign',
            'project_id' => $project_id, 'machine_id' => $resolved['id'], 'outcome' => 'assigned',
        ]);
        return $updated;
    }

    public function projectProvisionLocal(string $project_id, int $revision, string $confirmation): array
    {
        if ($confirmation !== 'PROVISION_LOCAL_WORKSPACE'
            || $this->provisioning === null || $this->localProvisioner === null) {
            throw new InvalidArgumentException('Provisionnement local indisponible ou non confirme');
        }
        $record = $this->provisioning->get($project_id);
        if ($record['revision'] !== $revision || $record['state'] !== 'machine_assigned'
            || $record['machine'] !== gethostname()) {
            throw new InvalidArgumentException('Projet non affecte a cet hote ou revision obsolete');
        }
        $this->audit->append([
            'project' => '_provisioning', 'action' => 'project.workspace.provision',
            'project_id' => $project_id, 'outcome' => 'requested',
        ]);
        try {
            $result = $this->localProvisioner->provision($project_id, $record['repository']);
        } catch (\Throwable $error) {
            $this->audit->append([
                'project' => '_provisioning', 'action' => 'project.workspace.provision',
                'project_id' => $project_id, 'outcome' => 'failed',
                'error_class' => get_class($error),
            ]);
            throw $error;
        }
        $updated = $this->provisioning->advance(
            $project_id, $revision, 'active', ['workspace_path' => $result['workspace_path']]
        );
        $this->audit->append([
            'project' => '_provisioning', 'action' => 'project.workspace.provision',
            'project_id' => $project_id, 'outcome' => 'active',
        ]);
        return $updated;
    }

    public function projectProvisioningList(): array
    {
        if ($this->provisioning === null) {
            return ['projects' => [], 'available' => false];
        }
        return ['projects' => $this->provisioning->list(), 'available' => true];
    }

    /**
     * Prepare a project/repository intent without creating either resource.
     * This endpoint is deliberately read-only, including for remote agents.
     */
    public function projectCreatePreview(
        string $project_id,
        string $description,
        string $github_repository,
        string $repository_mode,
        string $target,
        string $machine,
        string $workspace_path,
    ): array {
        $preview = ProjectCreationPreview::prepare(
            $project_id, $description, $github_repository, $repository_mode,
            $target, $machine, $workspace_path
        );
        $id = $preview['project']['id'];
        if (isset($this->projects->all()[$id])) {
            throw new InvalidArgumentException('Projet local deja enregistre');
        }
        // The distributed registry includes disabled projects invisible to project_list.
        $centralChecked = $this->remoteTools !== null;
        if ($centralChecked && $this->remoteTools->registeredProjectExists($id)) {
            throw new InvalidArgumentException('Projet distribue deja enregistre');
        }

        if ($target === 'remote') {
            if ($this->remoteTools === null) {
                throw new InvalidArgumentException('Registre distant indisponible');
            }
            $remoteMachine = $this->remoteTools->machineForProvisioningPreview($machine);
            $preview['destination']['machine'] = $remoteMachine['name'];
            $preview['destination']['machine_id'] = $remoteMachine['id'];
            $preview['destination']['machine_status'] = $remoteMachine['status'];
        } else {
            $hostname = gethostname();
            if ($machine !== 'local' && ($hostname === false || $machine !== $hostname)) {
                throw new InvalidArgumentException('Machine locale inconnue : utiliser local');
            }
            $preview['destination']['machine'] = $hostname ?: 'local';
            $preview['destination']['machine_id'] = null;
            $preview['destination']['machine_status'] = 'local';
        }

        $preview['checks'] = [
            'local_project_id_free' => true,
            'distributed_registry_checked' => $centralChecked,
            'distributed_project_id_free' => $centralChecked ? true : null,
            'machine_identity_checked' => true,
            'github_repository_checked' => false,
            'workspace_policy_checked' => false,
        ];
        $preview['next_step'] = 'Provisioning disabled: approve separate GitHub and machine policies before mutation';
        return $preview;
    }

    /**
     * Explicit, opt-in GitHub mutation only. Creating a repository never
     * creates a workspace, registers a project, or activates agent actions.
     */
    public function githubRepositoryCreate(string $repository, string $confirmation): array
    {
        if ($this->githubCreator === null) {
            throw new InvalidArgumentException('Creation GitHub desactivee par la politique centrale');
        }
        if (strlen($repository) > 140) {
            throw new InvalidArgumentException('Nom GitHub trop long');
        }
        // Audit before the external side effect. Never record a token or HTTP body.
        $this->audit->append([
            'project' => '_github',
            'action' => 'github.repository.create',
            'repository' => $repository,
            'outcome' => 'requested',
        ]);
        try {
            $created = $this->githubCreator->create($repository, $confirmation);
        } catch (\Throwable $error) {
            $this->audit->append([
                'project' => '_github', 'action' => 'github.repository.create',
                'repository' => $repository, 'outcome' => 'failed',
                'error_class' => get_class($error),
            ]);
            throw $error;
        }
        try {
            $this->audit->append([
                'project' => '_github', 'action' => 'github.repository.create',
                'repository' => $created['repository'], 'outcome' => 'created',
                'github_id' => $created['github_id'],
            ]);
            $created['audit_confirmed'] = true;
        } catch (\Throwable) {
            // Do not report a failed operation when GitHub already created it.
            $created['audit_confirmed'] = false;
        }
        return $created;
    }

    /**
     * @return array<string, mixed>
     */
    public function projectDescribe(string $project): array
    {
        if ($this->remoteTools !== null && $this->remoteTools->hasProject($project) && !isset($this->projects->all()[$project])) {
            return $this->remoteTools->describe($project);
        }
        if ($this->provisioning !== null) {
            foreach ($this->provisioning->list() as $entry) {
                if ($entry['project_id'] === $project) {
                    return $this->provisioning->get($project);
                }
            }
        }
        return $this->projects->get($project)->toPublicArray();
    }

    /**
     * @return array{project: string, actions: list<array<string, mixed>>}
     */
    public function actionList(string $project): array
    {
        if ($this->remoteTools !== null && $this->remoteTools->hasProject($project) && !isset($this->projects->all()[$project])) {
            return $this->remoteTools->actions($project);
        }
        if ($this->provisioning !== null && !isset($this->projects->all()[$project])) {
            try {
                $record = $this->provisioning->get($project);
                if (!$record['actions_enabled']) {
                    return ['project' => $project, 'actions' => []];
                }
            } catch (\RuntimeException) {
                // Not a provisioning project; retain legacy lookup behavior.
            }
        }
        $definition = $this->projects->get($project);
        $actions = [];

        foreach ($definition->getActions() as $action) {
            $actions[] = $action->toPublicArray();
        }

        return [
            'project' => $project,
            'actions' => $actions,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function actionDescribe(string $project, string $action): array
    {
        if ($this->remoteTools !== null && $this->remoteTools->hasProject($project) && !isset($this->projects->all()[$project])) {
            return $this->remoteTools->action($project, $action);
        }
        return $this->projects->get($project)->getAction($action)->toPublicArray();
    }

    /**
     * Exécution synchrone réservée aux actions courtes.
     *
     * @return array<string, mixed>
     */
    public function actionRun(
        string $project,
        string $action,
        array $arguments = [],
    ): array
    {
        if ($this->remoteTools !== null && $this->remoteTools->hasProject($project) && !isset($this->projects->all()[$project])) {
            throw new InvalidArgumentException('Remote actions require action_start');
        }
        $projectDefinition = $this->projects->get($project);
        $actionDefinition = $projectDefinition->getAction($action);

        if (!$actionDefinition->syncAllowed) {
            throw new InvalidArgumentException(
                "L'action {$action} n'autorise pas l'exécution synchrone ; utiliser action_start."
            );
        }

        if ($actionDefinition->target !== 'local') {
            throw new InvalidArgumentException(
                "L'action {$action} cible {$actionDefinition->target} ; utiliser action_start."
            );
        }

        return $this->runner->run($projectDefinition, $action, $arguments);
    }

    /**
     * Démarre une action longue et retourne immédiatement un job_id.
     *
     * @return array<string, mixed>
     */
    public function actionStart(
        string $project,
        string $action,
        array $arguments = [],
    ): array {
        if ($this->remoteTools !== null && $this->remoteTools->hasProject($project) && !isset($this->projects->all()[$project])) {
            return $this->remoteTools->start($project, $action, $arguments);
        }
        return $this->jobs->start($project, $action, $arguments);
    }

    /**
     * @return array<string, mixed>
     */
    public function jobStatus(string $job_id): array
    {
        if ($this->remoteTools !== null && $this->remoteTools->isRemoteJob($job_id)) {
            return $this->remoteTools->status($job_id);
        }
        return $this->jobs->status($job_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function jobOutput(
        string $job_id,
        int $stdout_offset = 0,
        int $stderr_offset = 0,
        ?int $length = null,
    ): array {
        if ($this->remoteTools !== null && $this->remoteTools->isRemoteJob($job_id)) {
            if ($stdout_offset !== 0 || $stderr_offset !== 0 || ($length !== null && $length < 1)) {
                throw new InvalidArgumentException('Remote output does not support offsets or custom chunk sizes');
            }
            $job = $this->remoteTools->status($job_id);
            return [...$job, 'stdout' => ['data' => '', 'next_offset' => 0, 'eof' => true], 'stderr' => ['data' => '', 'next_offset' => 0, 'eof' => true]];
        }
        return $this->jobs->output(
            $job_id,
            $stdout_offset,
            $stderr_offset,
            $length,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jobCancel(string $job_id): array
    {
        return $this->jobs->cancel($job_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function artifactList(string $job_id): array
    {
        return $this->jobs->artifactList($job_id);
    }

    /**
     * @return array<string, mixed>
     */
    public function artifactGet(
        string $job_id,
        string $artifact,
        int $offset = 0,
        ?int $length = null,
    ): array {
        return $this->jobs->artifactGet($job_id, $artifact, $offset, $length);
    }

    /**
     * @return array{project: string, records: list<array<string, mixed>>}
     */
    public function auditTail(string $project, int $limit = 20): array
    {
        $this->projects->get($project);

        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('limit doit être compris entre 1 et 100');
        }

        return [
            'project' => $project,
            'records' => $this->audit->tail($project, $limit),
        ];
    }
}
