<?php

declare(strict_types=1);

// Run without root, network, SQL, Composer or a systemd manager.
require_once dirname(__DIR__, 2) . '/src/Distributed/Admin/HostingLabBatchConsent.php';
require_once dirname(__DIR__, 2) . '/src/Distributed/Admin/HostingLabOperatorRequest.php';

use Cowprod\DevMcp\Distributed\Admin\HostingLabOperatorRequest;

function expectOperatorDenied(string $wire, string $machine = 'caseRouter'): void
{
    try {
        HostingLabOperatorRequest::parse($wire, $machine);
    } catch (Throwable) {
        return;
    }
    throw new RuntimeException('Central operator accepted a rejected request');
}
function operatorWire(string $phase = 'prepare', string $approval = 'PREPARE_CASE_HOSTING_LAB_BY_NAME'): string
{
    return json_encode([
        'version' => 1, 'phase' => $phase, 'machine_name' => 'caseRouter',
        'revision' => str_repeat('a', 40), 'approval' => $approval,
    ], JSON_THROW_ON_ERROR) . "\n";
}
$prepared = HostingLabOperatorRequest::parse(operatorWire(), 'caseRouter');
if ($prepared !== [
    'phase' => 'prepare', 'machine_name' => 'caseRouter',
    'revision' => str_repeat('a', 40),
]) {
    throw new RuntimeException('Unexpected central operator preparation');
}
$activated = HostingLabOperatorRequest::parse(
    operatorWire('activate', 'ACTIVATE_CASE_HOSTING_LAB_BY_NAME'), 'caseRouter'
);
if ($activated['phase'] !== 'activate') {
    throw new RuntimeException('Missing central activation gate');
}

expectOperatorDenied(operatorWire('activate')); // preparation approval != activation
expectOperatorDenied(operatorWire('prepare', 'ACTIVATE_CASE_HOSTING_LAB_BY_NAME'));
expectOperatorDenied(operatorWire(), 'case');
expectOperatorDenied(operatorWire(), 'CaseRouter');
expectOperatorDenied(operatorWire(), ''); // empty root policy
expectOperatorDenied(rtrim(operatorWire(), "\n")); // missing framing
expectOperatorDenied(operatorWire() . "garbage");
expectOperatorDenied(str_repeat('a', 513) . "\n");
expectOperatorDenied("[]\n");
expectOperatorDenied("{}\n");
expectOperatorDenied("null\n");
$valid = json_decode(operatorWire(), true, 6, JSON_THROW_ON_ERROR);
$invalid = [
    ['version' => 2],
    ['phase' => 'abort'],
    ['phase' => 'prepare-all'],
    ['phase' => 0],
    ['machine_name' => 'caseRouter;reboot'],
    ['machine_name' => 'case'],
    ['revision' => str_repeat('0', 40)],
    ['revision' => strtoupper(str_repeat('a', 40))],
    ['revision' => '../etc/passwd'],
    ['approval' => 'PREPARE_CASE_HOSTING_LAB'],
    ['approval' => ''],
    ['deployment_id' => str_repeat('a', 32)],
    ['machine_id' => str_repeat('b', 32)],
];
foreach ($invalid as $change) {
    expectOperatorDenied(json_encode(array_replace($valid, $change),
        JSON_THROW_ON_ERROR) . "\n");
}
foreach (['version', 'phase', 'machine_name', 'revision', 'approval'] as $field) {
    $missing = $valid;
    unset($missing[$field]);
    expectOperatorDenied(json_encode($missing, JSON_THROW_ON_ERROR) . "\n");
}

// The opt-in socket must never be available through global shared actions
// without a separate local project action grant.
$actions = require dirname(__DIR__, 2) . '/config/shared-actions.php';
foreach (['hosting.lab.prepare', 'hosting.lab.activate'] as $name) {
    if (!isset($actions[$name]) || ($actions[$name]['target'] ?? null) !== 'local'
        || ($actions[$name]['sync'] ?? false) !== false) {
        throw new RuntimeException('Central operator action not bounded and asynchronous');
    }
}
$socket = file_get_contents(dirname(__DIR__, 2)
    . '/deploy/distributed/devmcp-hosting-central-operator.socket');
$service = file_get_contents(dirname(__DIR__, 2)
    . '/deploy/distributed/devmcp-hosting-central-operator@.service');
$installer = file_get_contents(dirname(__DIR__, 2)
    . '/deploy/distributed/install-hosting-central-operator.sh');
if (!is_string($socket) || !str_contains($socket, 'SocketGroup=devmcp')
    || !str_contains($socket, 'SocketMode=0660')
    || !str_contains($socket, 'Accept=yes')
    || !is_string($service) || !str_contains($service, 'User=root')
    || !str_contains($service, 'StandardInput=socket')
    || !str_contains($service, 'ProtectSystem=strict')
    || !is_string($installer)
    || !str_contains($installer, '/opt/devmcp/deploy /opt/devmcp/deploy/distributed')
    || !str_contains($installer, 'trusted_dir "$dir"')
    || !str_contains($installer, 'hosting_lab_operator_socket_enabled')
    || !str_contains($installer, 'Central HTTPS hosting routes must be installed first')) {
    throw new RuntimeException('Untrusted central operator installation contract');
}
// The bootstrap must be reversible: protect pre-existing units and keep
// separate enabled/active states. Contract-only: no privileged systemd call.
foreach ([
    'trap rollback_operator_install EXIT',
    'was_active=0',
    'was_enabled=0',
    'systemctl is-active --quiet "$socket_unit"',
    'systemctl is-enabled --quiet "$socket_unit"',
    'installed_units=()',
    'staged_units=()',
    'stage="$(mktemp "/etc/systemd/system/.$name.XXXXXXXX")"',
    'mv -n -- "$stage" "$target"',
    'for name in "${installed_units[@]}"; do',
    'systemctl stop "$socket_unit"',
    'systemctl disable "$socket_unit"',
    'systemctl daemon-reload',
    'installation_committed=1',
] as $required) {
    if (!str_contains($installer, $required)) {
        throw new RuntimeException('Central operator transactional bootstrap contract missing: ' . $required);
    }
}
// Two simultaneous privileged installations must not race their state
// snapshots or let the rejected invocation disable the winner's socket.
foreach ([
    'lock_path="$lock_dir/devmcp-hosting-central-operator.lock"',
    'set -C; : > "$lock_path"',
    "'0:0:600:1'",
    'exec 9<>"$lock_path"',
    'flock -n 9',
    'Another central operator installation is in progress',
] as $required) {
    if (!str_contains($installer, $required)) {
        throw new RuntimeException('Central operator installation lacks exclusive root lock: ' . $required);
    }
}
$lockPos = strpos($installer, 'flock -n 9');
$statePos = strpos($installer, 'was_active=0');
if ($lockPos === false || $statePos === false || $lockPos >= $statePos) {
    throw new RuntimeException('Operator must hold lock before snapshotting socket state');
}
// If a move succeeds but its subsequent validation fails, the new file must
// already be in the rollback ledger. A no-replace skip leaves its stage.
$movePos = strpos($installer, 'mv -n -- "$stage" "$target"');
$trackPos = strpos($installer, 'installed_units+=("$name")');
$verifyPos = strpos($installer, 'cmp -s "$prefix/$name" "$target" || {', $movePos === false ? 0 : $movePos);
if ($movePos === false || $trackPos === false || $verifyPos === false
    || !($movePos < $trackPos && $trackPos < $verifyPos)
    || !str_contains($installer, '! -e "$stage" && -f "$target"')) {
    throw new RuntimeException('Post-move failure cannot be safely rolled back');
}
// Rollback must remove only newly installed units, never pre-existing copies.
if (!str_contains($installer, 'trusted_file "$target" && cmp -s "$prefix/$name" "$target"')
    || !str_contains($installer, 'if [[ "$rc" -ne 0 && "$mutation_started" -eq 1')
    || !str_contains($installer, 'if [[ "$was_active" -eq 0 ]]')
    || !str_contains($installer, 'if [[ "$was_enabled" -eq 0 ]]')) {
    throw new RuntimeException('Central operator rollback could destroy existing units');
}
echo "PASS: central three-demo root socket protocol, consent, opt-in and rollback contracts\n";
