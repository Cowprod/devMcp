<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Notification;

use Cowprod\DevMcp\Distributed\Admin\PreviewHttpProbe;
use Cowprod\DevMcp\Project\ProjectRuntimeCatalog;
use PDO;

final class PublishedSites
{
    private array $urls = [];

    public function __construct(private readonly PDO $db, private readonly string $machine, private readonly string $previewIp, private readonly array $runtimeConfig = [])
    {
    }

    public function url(array $job): ?string
    {
        $deployment = $job['deployment_id'] ?? null;
        $key = $job['source'] . ':' . $job['project'] . ':' . $deployment;
        if (!array_key_exists($key, $this->urls)) {
            $this->urls[$key] = $job['source'] === 'local'
                ? $this->localUrl($job['project'])
                : $this->remoteUrl($job['project'], $deployment);
        }
        return $this->urls[$key];
    }

    private function localUrl(string $project): ?string
    {
        if (!filter_var($this->previewIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return null;
        }
        $record = ProjectRuntimeCatalog::forRuntime($this->db, $this->runtimeConfig)->serviceRecord($project);
        if ($record === null || $record['machine'] !== $this->machine || $record['state'] !== 'active'
            || (int)$record['actions_enabled'] !== 1 || $record['desired_state'] !== 'running')
            return null;
        $port = (int)$record['port'];
        if ($port < 1024 || $port > 65535
            || PreviewHttpProbe::check($port, $this->previewIp)['state'] !== 'http') {
            return null;
        }
        return 'http://' . $this->previewIp . ':' . $port;
    }

    private function remoteUrl(string $project, ?string $deployment): ?string
    {
        if ($deployment === null) {
            return null;
        }
        $query = $this->db->prepare(
            'SELECT p.metadata_json FROM dm_remote_project p
             JOIN dm_deployment d ON d.id = p.deployment_id AND d.project_id = p.project_id AND d.machine_id = p.machine_id
             WHERE p.project_id = ? AND p.deployment_id = ? AND p.ready = 1 AND d.enabled = 1'
        );
        $query->execute([$project, $deployment]);
        $data = json_decode((string) $query->fetchColumn(), true);
        return ($data['central_http_verified'] ?? false) === true
            ? (FinishedJobs::links('deploy', 'succeeded', $data)['Site'] ?? null)
            : null;
    }
}
