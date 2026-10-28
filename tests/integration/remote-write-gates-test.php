<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/bin/remote-server-write-policy.php';
require_once dirname(__DIR__, 2) . '/bin/remote-job-enqueue.php';
require_once dirname(__DIR__, 2) . '/bin/remote-job-dispatch.php';
require_once dirname(__DIR__, 2) . '/bin/remote-job-lifecycle.php';

$checks = [];
$additionalProtectedNames = ['passwordVault.md', 'passwd.md', 'api_key.md', 'apikey.md',
    '.env_backup', '.envrc', '.npmrc.bak', 'id_rsa.old', 'certificate.pem.bak'];
$check = static function (string $name, bool $pass) use (&$checks): void {
    $checks[$name] = $pass;
    echo ($pass ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
};
$root = sys_get_temp_dir() . '/devmcp-write-gates-' . bin2hex(random_bytes(8));
mkdir($root, 0700);
mkdir($root . '/docs', 0700);
$state = $root . '-state';
mkdir($state, 0700);
file_put_contents($root . '/docs/smoke.md', "before\n");
$deployment = str_repeat('a', 32);
$machine = str_repeat('b', 32);
$args = ['path' => 'docs/smoke.md', 'content' => "after\n", 'expected_sha256' => hash('sha256', "before\n")];
$job = ['deployment_id' => $deployment, 'workspace_path' => $root, 'action' => 'workspace.write_text', 'arguments' => $args];
$server = ['enabled' => true, 'deployments' => [$deployment => ['enabled' => true, 'paths' => ['docs/smoke.md']]]];
$local = ['enabled' => true, 'deployments' => [$deployment => [
    'workspace_path' => $root, 'actions' => ['workspace.read', 'workspace.write_text'],
    'allow_write' => true, 'write_paths' => ['docs/smoke.md'],
]]];
try {
    // Guard the executable bootstrap config, not only the per-job policy.
    // Validate the security-critical ordering without requiring a root-owned
    // /etc/devmcp fixture on the unprivileged CI runner.
    $agentSource = file_get_contents(dirname(__DIR__, 2) . '/bin/distributed-agent.php');
    $configGuard = is_string($agentSource)
        ? strpos($agentSource, 'if (!remoteLocalPolicyFileTrusted($configPath))') : false;
    $configRequire = is_string($agentSource)
        ? strpos($agentSource, '$config = require $configPath;') : false;
    $check('agent bootstrap PHP trust guard precedes require',
        $configGuard !== false && $configRequire !== false && $configGuard < $configRequire);
    // PHP policy files must be validated before require, even for read-only jobs.
    $policyFixture = $root . '/local-policy.php';
    file_put_contents($policyFixture, '<?php return [];');
    $check('untrusted agent PHP policy rejected before require',
        !remoteLocalPolicyFileTrusted($policyFixture));
    $policyLink = $root . '/policy-link.php';
    symlink($policyFixture, $policyLink);
    $check('agent policy symlink rejected', !remoteLocalPolicyFileTrusted($policyLink));
    chmod($root, 0777);
    $check('agent policy in writable ancestor rejected', !remoteLocalPolicyFileTrusted($policyFixture));
    chmod($root, 0700);
    chmod($policyFixture, 0666);
    $check('writable agent policy file rejected', !remoteLocalPolicyFileTrusted($policyFixture));
    chmod($policyFixture, 0600);
    $check('server deny by default', !remoteServerWriteApproved([], $deployment, 'docs/smoke.md'));
    $check('server opt-in exact path', remoteServerWriteApproved($server, $deployment, 'docs/smoke.md'));
    $check('server rejects other path', !remoteServerWriteApproved($server, $deployment, 'docs/other.md'));
    $protectedServer = $server;
    $protectedServer['deployments'][$deployment]['paths'] = ['docs/Private-notes.md'];
    $check('protected-only server allowlist cannot advertise writes',
        !remoteServerWriteEnabledForDeployment($protectedServer, $deployment));
    $check('protected server path rejected even when explicitly listed',
        !remoteServerWriteApproved($protectedServer, $deployment, 'docs/Private-notes.md'));
    $mixedServer = $server;
    $mixedServer['deployments'][$deployment]['paths'][] = 'docs/credentials.txt';
    $check('mixed safe and protected server allowlist fails closed',
        !remoteServerWriteEnabledForDeployment($mixedServer, $deployment));
    $check('server rejects other deployment', !remoteServerWriteApproved($server, str_repeat('c', 32), 'docs/smoke.md'));
    $check('server requires global switch', !remoteServerWriteApproved(['deployments' => $server['deployments']], $deployment, 'docs/smoke.md'));
    $check('server requires deployment switch', !remoteServerWriteApproved(['enabled' => true,
        'deployments' => [$deployment => ['paths' => ['docs/smoke.md']]]], $deployment, 'docs/smoke.md'));
    $check('job write denied by default', !remoteJobAllowed('workspace.write_text', $args));
    $check('job accepted with explicit capability', remoteJobAllowed('workspace.write_text', $args, true));
    foreach (['docs/secret-notes.md', 'docs/CREDENTIALS.txt', 'private/notes.md', 'docs/apiToken.md',
        'docs/passwordVault.md', 'docs/passwd.md', 'docs/api_key.md', 'docs/apikey.md'] as $unsafePath) {
        $check('job denies protected path ' . $unsafePath,
            !remoteJobAllowed('workspace.write_text', [...$args, 'path' => $unsafePath], true));
    }
    $check('similarly named safe path remains eligible',
        remoteJobAllowed('workspace.write_text', [...$args, 'path' => 'docs/notes.md'], true));
    $check('write extension deny', !remoteJobAllowed('workspace.write_text', [...$args, 'path' => 'docs/smoke.php'], true));
    $check('write traversal deny', !remoteJobAllowed('workspace.write_text', [...$args, 'path' => '../smoke.md'], true));
    $check('write extra key deny', !remoteJobAllowed('workspace.write_text', [...$args, 'argv' => 'sh'], true));
    $check('write oversized deny', !remoteJobAllowed('workspace.write_text', [...$args, 'content' => str_repeat('x', 16385)], true));
    $check('write invalid utf8 deny', !remoteJobAllowed('workspace.write_text', [...$args, 'content' => chr(255)], true));
    $check('write NUL deny', !remoteJobAllowed('workspace.write_text', [...$args, 'content' => "x\0y"], true));
    $check('write invalid sha deny', !remoteJobAllowed('workspace.write_text', [...$args, 'expected_sha256' => 'abc'], true));
    $check('successful write result drops agent-supplied content',
        remoteNormalizeWriteResult(['ok' => true, 'sha256' => hash('sha256', "after\n"),
            'size' => 6, 'content' => 'sensitive'], "after\n") ===
        ['ok' => true, 'sha256' => hash('sha256', "after\n"), 'size' => 6]);
    $check('reported write SHA must match queued content',
        remoteNormalizeWriteResult(['ok' => true, 'sha256' => str_repeat('a', 64),
            'size' => 6], "after\n") === ['ok' => false, 'error' => 'write_result_mismatch']);
    $check('reported write size must match queued byte count',
        remoteNormalizeWriteResult(['ok' => true, 'sha256' => hash('sha256', "after\n"),
            'size' => 5], "after\n") === ['ok' => false, 'error' => 'write_result_mismatch']);
    $check('scrubbed write payload makes reported success unverifiable',
        remoteNormalizeWriteResult(['ok' => true, 'sha256' => hash('sha256', "after\n"),
            'size' => 6]) === ['ok' => false, 'error' => 'write_result_unverifiable']);
    $check('failed write result drops agent-supplied content',
        remoteNormalizeWriteResult(['ok' => false, 'error' => 'sha256_conflict',
            'content' => 'sensitive']) === ['ok' => false, 'error' => 'sha256_conflict']);
    $check('malformed success is not treated as verified',
        remoteNormalizeWriteResult(['ok' => true, 'content' => 'sensitive']) ===
        ['ok' => false, 'error' => 'invalid_write_result']);
    $check('invalid error text is not stored',
        remoteNormalizeWriteResult(['ok' => false, 'error' => "failed\nsecret"]) ===
        ['ok' => false, 'error' => 'invalid_write_result']);
    $check('read-only legacy action unchanged', remoteJobAllowed('workspace.read', ['path' => 'docs/smoke.md']));
    $check('locally approved write accepted', remoteLocalPolicyCheck($local, $job));
    $protectedLocal = $local;
    $protectedLocal['deployments'][$deployment]['write_paths'][] = 'docs/secret-notes.md';
    $protectedJob = $job;
    $protectedJob['arguments']['path'] = 'docs/secret-notes.md';
    $check('local policy denies protected path despite allowlist',
        !remoteLocalPolicyCheck($protectedLocal, $protectedJob));
    $check('local denies by default', !remoteLocalPolicyCheck([], $job));
    $localNoWrite = $local;
    $localNoWrite['deployments'][$deployment]['allow_write'] = false;
    $check('local separate write switch required', !remoteLocalPolicyCheck($localNoWrite, $job));
    $localOtherPath = $local;
    $localOtherPath['deployments'][$deployment]['write_paths'] = ['docs/other.md'];
    $check('local exact write path required', !remoteLocalPolicyCheck($localOtherPath, $job));
    $wrongWorkspace = $job;
    $wrongWorkspace['workspace_path'] = '/tmp/not-the-approved-workspace';
    $check('local pinned workspace required', !remoteLocalPolicyCheck($local, $wrongWorkspace));
    $readJob = ['deployment_id' => $deployment, 'workspace_path' => $root, 'action' => 'workspace.read',
        'arguments' => ['path' => 'docs/smoke.md']];
    $check('read through dispatcher', (remoteAuthorizedJobDispatch($local, $readJob)['content'] ?? null) === "before\n");
    $check('untrusted policy file denied', !(remoteAuthorizedJobDispatch($local, $job, false)['ok'] ?? false));
    // Read-only inspection must not expose names that the write side treats
    // as sensitive. Test direct reads and both discovery APIs.
    file_put_contents($root . '/docs/apiToken.md', "TOKEN_SENTINEL\n");
    file_put_contents($root . '/docs/privateNotes.md', "PRIVATE_SENTINEL\n");
    foreach ($additionalProtectedNames as $protectedName) {
        file_put_contents($root . '/docs/' . $protectedName, "PROTECTED_SENTINEL\n");
        $check('read-only read denies ' . $protectedName,
            (remoteWorkspaceAction($root, 'workspace.read', ['path' => 'docs/' . $protectedName])['error'] ?? null)
                === 'protected_path');
        $check('read-only stat denies ' . $protectedName,
            (remoteWorkspaceAction($root, 'workspace.stat', ['path' => 'docs/' . $protectedName])['error'] ?? null)
                === 'protected_path');
    }
    $check('read-only token file blocked', (remoteWorkspaceAction($root, 'workspace.read',
        ['path' => 'docs/apiToken.md'])['error'] ?? null) === 'protected_path');
    $check('read-only private file blocked', (remoteWorkspaceAction($root, 'workspace.read',
        ['path' => 'docs/privateNotes.md'])['error'] ?? null) === 'protected_path');
    $check('read-only token stat blocked', (remoteWorkspaceAction($root, 'workspace.stat',
        ['path' => 'docs/apiToken.md'])['error'] ?? null) === 'protected_path');
    $listing = remoteWorkspaceAction($root, 'workspace.list', ['path' => 'docs']);
    $listedNames = array_column($listing['entries'] ?? [], 'name');
    $check('read-only list hides password and API key names',
        array_intersect($additionalProtectedNames, $listedNames) === []);
    $check('read-only list hides sensitive filenames', ($listing['ok'] ?? false) === true
        && !in_array('apiToken.md', $listedNames, true)
        && !in_array('privateNotes.md', $listedNames, true)
        && in_array('smoke.md', $listedNames, true));
    $found = remoteWorkspaceAction($root, 'workspace.find', ['path' => 'docs', 'filter' => 'md']);
    $check('read-only find hides password and API key names',
        array_intersect(array_map(static fn(string $name): string => 'docs/' . $name, $additionalProtectedNames),
            $found['files'] ?? []) === []);
    $check('read-only find hides sensitive filenames', ($found['ok'] ?? false) === true
        && !in_array('docs/apiToken.md', $found['files'] ?? [], true)
        && !in_array('docs/privateNotes.md', $found['files'] ?? [], true)
        && in_array('docs/smoke.md', $found['files'] ?? [], true));
    $foundAll = remoteWorkspaceAction($root, 'workspace.find', ['path' => 'docs', 'filter' => 'all']);
    $check('read-only find all hides protected backups',
        ($foundAll['ok'] ?? false) === true
        && array_intersect(
            array_map(static fn(string $name): string => 'docs/' . $name, $additionalProtectedNames),
            $foundAll['files'] ?? []
        ) === []
        && in_array('docs/smoke.md', $foundAll['files'] ?? [], true));
    $check('read-only safe path remains accessible',
        (remoteWorkspaceAction($root, 'workspace.read',
            ['path' => 'docs/smoke.md'])['content'] ?? null) === "before\n");
    // A benign-looking hardlink can alias a secret outside the workspace.
    // realpath() alone cannot distinguish a hardlink from a normal file.
    $outsideFile = $root . '-outside.txt';
    $insideAlias = $root . '/docs/visible.md';
    file_put_contents($outsideFile, "OUTSIDE_HARDLINK_SENTINEL\n");
    if (!link($outsideFile, $insideAlias)) {
        throw new RuntimeException('Cannot create disposable hardlink fixture');
    }
    try {
        $hardlinkResult = remoteWorkspaceAction($root, 'workspace.read', ['path' => 'docs/visible.md']);
        $check('read-only hardlink outside workspace refuses content',
            ($hardlinkResult['error'] ?? null) === 'hardlinked_file_forbidden'
            && !array_key_exists('content', $hardlinkResult));
        $hardlinkStat = remoteWorkspaceAction($root, 'workspace.stat', ['path' => 'docs/visible.md']);
        $check('read-only stat does not disclose external hardlink metadata',
            ($hardlinkStat['error'] ?? null) === 'hardlinked_file_forbidden'
            && !array_key_exists('size', $hardlinkStat));
        $check('read-only ordinary file still available beside hardlink',
            (remoteWorkspaceAction($root, 'workspace.read', ['path' => 'docs/smoke.md'])['content'] ?? null)
                === "before\n");
        $check('read-only ordinary file still has stat metadata beside hardlink',
            (remoteWorkspaceAction($root, 'workspace.stat', ['path' => 'docs/smoke.md'])['size'] ?? null) === 7);
    } finally {
        unlink($insideAlias);
        unlink($outsideFile);
    }

    if (!extension_loaded('pdo_sqlite')) {
        throw new RuntimeException('pdo_sqlite required for queue smoke');
    }
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('CREATE TABLE dm_machine (id TEXT PRIMARY KEY, status TEXT NOT NULL)');
    $db->exec('CREATE TABLE dm_deployment (id TEXT PRIMARY KEY, machine_id TEXT NOT NULL, enabled INTEGER NOT NULL)');
    $db->exec('CREATE TABLE dm_remote_readonly_job (id TEXT PRIMARY KEY, deployment_id TEXT, machine_id TEXT, action_name TEXT, arguments_json TEXT, state TEXT)');
    $db->exec('CREATE TABLE dm_audit_event (id INTEGER PRIMARY KEY AUTOINCREMENT, actor TEXT, event_type TEXT, object_type TEXT, object_id TEXT, outcome TEXT, details_json TEXT)');
    $db->prepare('INSERT INTO dm_machine (id,status) VALUES (?,?)')->execute([$machine, 'active']);
    $db->prepare('INSERT INTO dm_deployment (id,machine_id,enabled) VALUES (?,?,?)')->execute([$deployment, $machine, 1]);
    $notEnqueued = false;
    try {
        enqueueRemoteReadonlyJob($db, $deployment, 'workspace.write_text', $args);
    } catch (InvalidArgumentException $error) {
        $notEnqueued = true;
    }
    $check('queue rejects missing write approval', $notEnqueued);
    $protectedNotEnqueued = false;
    try {
        enqueueRemoteReadonlyJob($db, $deployment, 'workspace.write_text',
            [...$args, 'path' => 'docs/secret-notes.md'], true);
    } catch (InvalidArgumentException $error) {
        $protectedNotEnqueued = true;
    }
    $check('queue rejects protected content even with write opt-in', $protectedNotEnqueued);
    $check('rejected content left no write rows in SQL queue',
        (int) $db->query("SELECT COUNT(*) FROM dm_remote_readonly_job WHERE action_name='workspace.write_text'")->fetchColumn() === 0);
    $id = enqueueRemoteReadonlyJob($db, $deployment, 'workspace.write_text', $args, true);
    $audit = $db->prepare('SELECT actor,event_type,details_json FROM dm_audit_event WHERE object_id = ?');
    $audit->execute([$id]);
    $auditRow = $audit->fetch(PDO::FETCH_ASSOC);
    $auditDetails = is_array($auditRow) ? json_decode($auditRow['details_json'], true) : null;
    $check('write enqueue audited with digest and no content', is_array($auditRow)
        && $auditRow['event_type'] === 'remote.write.queued'
        && ($auditDetails['new_sha256'] ?? null) === hash('sha256', "after\n")
        && !str_contains($auditRow['details_json'], '"content"'));
    $query = $db->prepare('SELECT * FROM dm_remote_readonly_job WHERE id = ?');
    $query->execute([$id]);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    $check('queue stored exactly one job', is_array($row) && $row['state'] === 'queued'
        && $row['machine_id'] === $machine && $row['action_name'] === 'workspace.write_text');
    $claimedJob = $job;
    $claimedJob['arguments'] = json_decode($row['arguments_json'], true, 8, JSON_THROW_ON_ERROR);
    $check('private state directory required', (remoteAuthorizedJobDispatch($local, $claimedJob, true)['ok'] ?? false) === false);
    chmod($state, 0777);
    $check('writable lock directory rejected', (remoteAuthorizedJobDispatch($local, $claimedJob, true, $state)['ok'] ?? false) === false);
    chmod($state, 0700);
    $result = remoteAuthorizedJobDispatch($local, $claimedJob, true, $state);
    $check('queue-to-agent dispatch succeeded', ($result['ok'] ?? false) === true);
    $check('replacement content matches', file_get_contents($root . '/docs/smoke.md') === "after\n");
    $check('new content hash matches', ($result['sha256'] ?? null) === hash('sha256', "after\n"));
    $again = remoteAuthorizedJobDispatch($local, $claimedJob, true, $state);
    $check('duplicate attempt rejected by CAS', ($again['error'] ?? null) === 'sha256_conflict');
    $check('duplicate preserved content', file_get_contents($root . '/docs/smoke.md') === "after\n");
    symlink($root . '/docs/smoke.md', $root . '/docs/link.md');
    $symlinkJob = $job;
    $symlinkJob['arguments'] = [...$args, 'path' => 'docs/link.md', 'expected_sha256' => hash('sha256', "after\n")];
    $localSymlink = $local;
    $localSymlink['deployments'][$deployment]['write_paths'][] = 'docs/link.md';
    $check('symlink target rejected', !(remoteAuthorizedJobDispatch($localSymlink, $symlinkJob, true, $state)['ok'] ?? false));
} finally {
    @unlink($root . '/policy-link.php');
    @unlink($root . '/local-policy.php');
    @unlink($root . '/docs/link.md');
    @unlink($root . '/docs/apiToken.md');
    @unlink($root . '/docs/privateNotes.md');
    foreach ($additionalProtectedNames as $protectedName) {
        @unlink($root . '/docs/' . $protectedName);
    }
    @unlink($root . '/docs/smoke.md');
    @unlink($state . '/remote-write-' . hash('sha256', $root) . '.lock');
    @rmdir($state);
    @rmdir($root . '/docs');
    @rmdir($root);
}
echo count($checks) . ' checks, ' . count(array_filter($checks)) . ' passed' . PHP_EOL;
exit(in_array(false, $checks, true) ? 1 : 0);
