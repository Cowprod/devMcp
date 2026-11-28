<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Notification;

use Cowprod\DevMcp\Execution\FileJobStore;
use DateTimeImmutable;
use DirectoryIterator;
use Generator;
use PDO;
use PDOException;

final class FinishedJobs
{
    public const TERMINAL = ['succeeded', 'failed', 'cancelled', 'timed_out', 'expired', 'uncertain'];

    public static function local(string $directory, DateTimeImmutable $since): Generator
    {
        if (!is_dir($directory)) {
            return;
        }

        $store = new FileJobStore($directory);
        foreach (new DirectoryIterator($directory) as $entry) {
            $id = $entry->getFilename();
            if (!preg_match('/^[a-f0-9]{32}$/D', $id) || !$entry->isDir() || $entry->isLink()) {
                continue;
            }
            $metadata = $entry->getPathname() . '/job.json';
            if (is_link($metadata) || !is_file($metadata) || filemtime($metadata) < $since->getTimestamp()) {
                continue;
            }
            $job = $store->get($id);
            if ($job->finishedAt !== null && in_array($job->status, self::TERMINAL, true)
                && new DateTimeImmutable($job->finishedAt) >= $since) {
                $result = [];
                if ($job->status === 'succeeded' && in_array($job->actionId, ['ios.build', 'android.build', 'project.deploy', 'project.service'], true)) {
                    $outputPath = $entry->getPathname() . '/stdout.log';
                    if (is_file($outputPath) && !is_link($outputPath)) {
                        $output = $store->readOutput($id, 'stdout', 0, 65_536);
                        $result = self::outputResult($output['content']);
                    }
                }
                yield ['links' => self::links($job->actionId, $job->status, $result), 'id' => $id, 'source' => 'local', 'project' => $job->projectId,
                    'action' => $job->actionId, 'status' => $job->status];
            }
        }
    }

    public static function remote(PDO $db, DateTimeImmutable $since, bool $cowprodDeployments = false): Generator
    {
        $deploymentTable = $cowprodDeployments ? 'V_DEPLOYMENTHISTORY' : 'dm_deployment';
        $queries = [
            'project' => 'SELECT id, project_id AS project, operation AS action, state AS status, result_json, deployment_id FROM dm_remote_project_job WHERE finished_at >= ?',
            'readonly' => 'SELECT j.id, j.deployment_id, d.project_id AS project, j.action_name AS action, j.state AS status FROM dm_remote_readonly_job j JOIN '.$deploymentTable.' d ON d.id=j.deployment_id WHERE j.finished_at >= ?',
            'hosting' => 'SELECT j.id, j.deployment_id, d.project_id AS project, j.action_name AS action, j.state AS status FROM dm_remote_hosting_job j JOIN '.$deploymentTable.' d ON d.id=j.deployment_id WHERE j.finished_at >= ?',
            'distributed' => "SELECT j.id, j.deployment_id, d.project_id AS project, j.action_id AS action, j.status FROM dm_job j JOIN {$deploymentTable} d ON d.id=j.deployment_id WHERE j.updated_at >= ? AND j.status IN ('succeeded','failed','cancelled','timed_out','expired','uncertain')",
        ];
        foreach ($queries as $source => $sql) {
            try {
                $query = $db->prepare($sql);
                $query->execute([$since->format('Y-m-d H:i:s.u')]);
            } catch (PDOException $error) {
                if (in_array($error->getCode(), ['42S02', 'HY000'], true) && ($error->errorInfo[1] ?? null) === ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 1 : 1146)) {
                    continue; // Queue is optional on this installation.
                }
                // A restricted optional queue must not suppress the other sources.
                fwrite(STDERR, "devMCP notification source $source unavailable (SQLSTATE {$error->getCode()})\n");
                continue;
            }
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                if (in_array($row['status'], self::TERMINAL, true)) {
                    $result = isset($row['result_json']) ? json_decode($row['result_json'], true) : null;
                    unset($row['result_json']);
                    $data = is_array($result['data'] ?? null) ? $result['data'] : [];
                    yield $row + ['source' => $source, 'links' => self::links($row['action'], $row['status'], $data)];
                }
            }
        }
    }

    public static function links(string $action, string $status, array $data): array
    {
        if ($status !== 'succeeded' || ($data['ok'] ?? true) !== true) {
            return [];
        }

        $links = [];
        if (in_array($action, ['ios.build', 'android.build', 'ios_build', 'android_build'], true)) {
            $page = $data['build_url'] ?? null;
            if (is_string($page) && preg_match('~^https://[a-zA-Z0-9.-]+(?::[0-9]{1,5})?/\?(?:ios_artifact|mobile_artifact|local_artifact)=[a-f0-9]{64}$~D', $page)) {
                $label = ($data['installation_available'] ?? false) === true ? 'Installation' : 'Page du build';
                $links[$label] = $page;
            }
        } elseif (in_array($action, ['deploy', 'project.deploy', 'project.service', 'restart'], true)) {
            $url = $data['url'] ?? null;
            if (is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
                && preg_match('~^https?://[a-zA-Z0-9.-]+(?::[0-9]{1,5})?/?$~D', $url)) {
                $links['Site'] = $url;
            }
        }
        return $links;
    }

    private static function outputResult(string $output): array
    {
        $document = json_decode($output, true);
        if (is_array($document)) {
            return $document;
        }
        // Local actions may print build progress before the final JSON line.
        foreach (array_reverse(explode("\n", trim($output))) as $line) {
            $document = json_decode($line, true);
            if (is_array($document)) {
                return $document;
            }
        }
        return [];
    }
}
