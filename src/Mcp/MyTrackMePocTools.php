<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Mcp;

final class MyTrackMePocTools
{
    private const ACTIONS = [
        'mtm_get_field_types', 'mtm_list_databases', 'mtm_create_mock_database',
        'mtm_get_design_state', 'mtm_record_design_turn', 'mtm_finalize_design',
        'mtm_design_report', 'mtm_propose_model', 'mtm_approve_plan',
        'mtm_get_plan', 'mtm_simulate_plan',
    ];

    public function call(string $action, string $arguments_json = '{}'): array
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Action MyTrackMe POC non autorisee');
        }
        if (strlen($arguments_json) > 50000) {
            throw new \InvalidArgumentException('Arguments trop volumineux');
        }
        $args = json_decode($arguments_json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($args) || array_is_list($args) && $args !== []) {
            throw new \InvalidArgumentException('Objet JSON attendu');
        }
        $entry = '/srv/devmcp/workspaces/chattrackme/devmcp-bridge-cli.js';
        if (!is_file($entry)) {
            throw new \RuntimeException('Bridge chattrackme non deploye');
        }
        $encoded = rtrim(strtr(base64_encode($arguments_json), '+/', '-_'), '=');
        $process = proc_open(['/usr/bin/node', $entry, $action, $encoded],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes,
            '/srv/devmcp/workspaces/chattrackme');
        if (!is_resource($process)) throw new \RuntimeException('Node indisponible');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1], 1000000);
        $error = stream_get_contents($pipes[2], 2048);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new \RuntimeException('Simulation echouee: ' . trim((string) $error));
        }
        $value = json_decode((string) $output, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new \RuntimeException('Reponse POC invalide');
        return $value;
    }
}
