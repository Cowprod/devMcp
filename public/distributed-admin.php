<?php

declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Admin\AndroidSnapshot;
use Cowprod\DevMcp\Distributed\Admin\DashboardRepository;
use Cowprod\DevMcp\Distributed\Admin\LocalProjectSnapshot;
use Cowprod\DevMcp\Distributed\Admin\RegistryEditor;
use Cowprod\DevMcp\Distributed\Enrollment\EnrollmentService;
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Distributed\Registry\RegistryRepository;

require dirname(__DIR__) . '/vendor/autoload.php';

header('Cache-Control: no-store');
$configPath = getenv('DEVMCP_DISTRIBUTED_CONFIG');
if (!is_string($configPath) || !is_file($configPath)) {
    http_response_code(503);
    exit('Distributed admin unavailable');
}
$config = require $configPath;
// Authentication must be enforced by the reverse proxy. Never accept a
// client-supplied identity header without trusted upstream validation.
if (($_SERVER['HTTPS'] ?? '') !== 'on'
    || ($_SERVER['REMOTE_USER'] ?? '') === '') {
    http_response_code(403);
    exit('Access denied');
}
// Screenshots are served only to authenticated HTTPS administrators, by serial.
if (isset($_GET['adb_image'])) {
    $serial = (string) $_GET['adb_image'];
    $file = AndroidSnapshot::SCREENSHOT_DIRECTORY . '/' . $serial . '.png';
    if (!AndroidSnapshot::validSerial($serial) || is_link($file) || !is_file($file)
        || !is_readable($file) || filesize($file) > 12582912 || filesize($file) < 64) {
        http_response_code(404);
        exit('Capture indisponible');
    }
    $stream = fopen($file, 'rb');
    if ($stream === false || fread($stream, 8) !== "\x89PNG\r\n\x1a\n") {
        if (is_resource($stream)) { fclose($stream); }
        http_response_code(404);
        exit('Capture invalide');
    }
    rewind($stream);
    header('Content-Type: image/png');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: inline; filename="capture-' . $serial . '.png"');
    fpassthru($stream);
    fclose($stream);
    exit;
}
session_start(['cookie_httponly' => true, 'cookie_secure' => true, 'cookie_samesite' => 'Strict', 'use_strict_mode' => true]);
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$androidSnapshot = AndroidSnapshot::read();
$adbFresh = $androidSnapshot['ok']
    && strtotime($androidSnapshot['generated_at_utc']) >= time() - 120;
$error = null;
$invitation = null;
$bootstrapCert = '/etc/devmcp-admin/tls/admin.crt';
$bootstrapCertHash = is_readable($bootstrapCert) ? hash_file('sha256', $bootstrapCert) : false;
$bootstrapServer = 'https://192.168.194.189';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(403);
        exit('Invalid CSRF token');
    }
    try {
        if (($_POST['operation'] ?? '') === 'android.screenshot.request') {
            $serial = (string) ($_POST['device'] ?? '');
            $online = false;
            if ($adbFresh && AndroidSnapshot::validSerial($serial)) {
                foreach ($androidSnapshot['devices'] as $device) {
                    if ($device['serial'] === $serial && $device['state'] === 'device') {
                        $online = true;
                        break;
                    }
                }
            }
            if (!$online || !is_dir(AndroidSnapshot::REQUEST_DIRECTORY)) {
                throw new InvalidArgumentException('Appareil ADB indisponible ou inventaire périmé.');
            }
            $queued = glob(AndroidSnapshot::REQUEST_DIRECTORY . '/*.req') ?: [];
            if (count($queued) >= 20) {
                throw new InvalidArgumentException('File de captures saturée (20 demandes maximum).');
            }
            foreach ($queued as $file) {
                if (str_ends_with($file, '-' . $serial . '.req')) {
                    throw new InvalidArgumentException('Une capture est déjà en attente pour cet appareil.');
                }
            }
            $request = AndroidSnapshot::REQUEST_DIRECTORY . '/' . bin2hex(random_bytes(16)) . '-' . $serial . '.req';
            $handle = @fopen($request, 'x');
            if ($handle === false) {
                throw new RuntimeException('Impossible de mettre la capture en file.');
            }
            fclose($handle);
            header('Location: /?adb_pending=' . rawurlencode($serial) . '#android-adb', true, 303);
            exit;
        }
        if (!isset($config['writer_username'], $config['writer_password'])) {
            throw new RuntimeException('Write access not configured');
        }
        $writer = DatabaseConnection::connect((string) $config['dsn'], (string) $config['writer_username'], (string) $config['writer_password']);
        $editor = new RegistryEditor($writer);
        switch ($_POST['operation'] ?? '') {
            case 'machine.create':
                $editor->createMachine((string) ($_POST['name'] ?? ''), (string) ($_POST['os'] ?? ''), (string) ($_POST['architecture'] ?? ''));
                break;
            case 'enrollment.issue':
                $invitation = (new EnrollmentService($writer))->issue((string) ($_POST['machine_id'] ?? ''));
                break;
            case 'machine.approve':
                if (empty($config['distributed_execution_approved'])) {
                    throw new InvalidArgumentException('Activation désactivée : validation de sécurité requise');
                }
                $editor->approveMachine((string) ($_POST['machine_id'] ?? ''));
                break;
            case 'project.create':
                $editor->createProject((string) ($_POST['id'] ?? ''), (string) ($_POST['description'] ?? ''), (string) ($_POST['repository_url'] ?? ''));
                break;
            case 'remote.readonly.enqueue':
                require dirname(__DIR__) . '/bin/remote-job-enqueue.php';
                $args = [];
                if (($_POST['action_name'] ?? '') !== 'git.head') {
                    $args['path'] = (string) ($_POST['path'] ?? '.');
                }
                if (($_POST['action_name'] ?? '') === 'workspace.find') {
                    $args['filter'] = (string) ($_POST['filter'] ?? 'all');
                }
                enqueueRemoteReadonlyJob($writer, (string) ($_POST['deployment_id'] ?? ''),
                    (string) ($_POST['action_name'] ?? ''), $args);
                break;
            case 'deployment.create':
                $editor->assignDeployment((string) ($_POST['project_id'] ?? ''), (string) ($_POST['machine_id'] ?? ''), (string) ($_POST['workspace_path'] ?? ''));
                break;
            default:
                throw new InvalidArgumentException('Unknown operation');
        }
        if ($invitation === null) {
            header('Location: /?created=1', true, 303);
            exit;
        }
    } catch (Throwable $exception) {
        $reference = bin2hex(random_bytes(4));
        error_log('devmcp-admin write [' . $reference . '] ' . get_class($exception) . ': ' . substr($exception->getMessage(), 0, 500));
        if ($exception instanceof InvalidArgumentException) {
            $error = $exception->getMessage();
        } elseif ($exception instanceof PDOException) {
            $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            $error = match ($driverCode) {
                1062 => 'Cet enregistrement existe déjà.',
                1142, 1143 => 'Droits SQL insuffisants pour cette opération.',
                1452 => 'Machine ou projet introuvable dans le registre.',
                default => 'Erreur SQL pendant l’enregistrement.',
            };
            $error .= ' Référence : ' . $reference . ' (SQLSTATE ' . $sqlState . ').';
        } else {
            $error = 'Enregistrement impossible. Référence : ' . $reference . '.';
        }
    }
}
try {
    $db = DatabaseConnection::connect(
        (string) ($config['dsn'] ?? ''),
        (string) ($config['username'] ?? ''),
        (string) ($config['password'] ?? ''),
    );
    $registry = new RegistryRepository($db);
    $dashboard = new DashboardRepository($db);
    $machines = $registry->machines();
    $projects = $registry->projects();
    $deployments = $registry->deployments();
    $jobs = $dashboard->recentJobs();
    $counts = $dashboard->jobCounts();
} catch (Throwable $exception) {
    http_response_code(503);
    exit('Dashboard temporarily unavailable');
}
// Keep distributed registry writes separate from local runtime definitions.
$localSnapshot = LocalProjectSnapshot::read(LocalProjectSnapshot::DEFAULT_PATH);
$localFresh = $localSnapshot['ok']
    && strtotime($localSnapshot['generated_at_utc']) >= time() - 600;
$projectRows = [];
foreach ($projects as $project) {
    $projectRows[$project['id']] = [
        'id' => $project['id'],
        'description' => $project['description'],
        'repository_url' => $project['repository_url'],
        'origin' => 'Distribué',
        'workspace' => '',
        'actions' => [],
    ];
}
foreach ($localSnapshot['projects'] as $project) {
    $id = $project['id'];
    if (isset($projectRows[$id])) {
        $projectRows[$id]['origin'] = 'Local + distribué';
    } else {
        $projectRows[$id] = [
            'id' => $id,
            'description' => $project['description'],
            'repository_url' => '',
            'origin' => 'Local',
            'workspace' => '',
            'actions' => [],
        ];
    }
    $projectRows[$id]['workspace'] = $project['workspace_path'];
    $projectRows[$id]['actions'] = $project['actions'];
}
ksort($projectRows);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>devMcp — Administration distribuée</title>
<link rel="icon" type="image/webp" href="/devmcp-icon-512.webp">
<link href="https://cdn.jsdelivr.net/npm/bootswatch@5.3.3/dist/quartz/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" rel="stylesheet">
</head>
<body>
<main class="container py-4">
<header class="d-flex justify-content-between align-items-center mb-4">
<h1 class="h3 mb-0">devMcp <span class="text-secondary">/ Distribué</span></h1>
<span class="badge bg-secondary ms-2 border border-light">Registre</span>
</header>
<section class="card mb-4"><div class="card-body">
<h2 class="h5">Action distante en lecture seule</h2>
<p class="text-secondary">Nécessite une machine active, un déploiement activé et une politique locale correspondante.</p>
<form method="post" action="/">
<input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
<input type="hidden" name="operation" value="remote.readonly.enqueue">
<div class="input-group mb-2"><span class="input-group-text">Déploiement</span><select class="form-select" name="deployment_id" required>
<?php foreach ($deployments as $deployment): ?>
<option value="<?= e($deployment['id']) ?>"><?= e($deployment['project_id'] . ' / ' . $deployment['machine_name']) ?></option>
<?php endforeach; ?></select></div>
<div class="input-group mb-2"><span class="input-group-text">Action</span><select class="form-select" name="action_name">
<?php foreach (['git.head','workspace.list','workspace.read','workspace.stat','workspace.find'] as $action): ?>
<option value="<?= e($action) ?>"><?= e($action) ?></option>
<?php endforeach; ?></select></div>
<div class="input-group mb-2"><span class="input-group-text">Chemin</span><input class="form-control" name="path" value="."></div>
<div class="input-group mb-3"><span class="input-group-text">Filtre</span><select class="form-select" name="filter">
<?php foreach (['all','php','js','json','md'] as $filter): ?><option value="<?= e($filter) ?>"><?= e($filter) ?></option><?php endforeach; ?>
</select></div>
<div class="btn-group"><button type="submit" class="btn btn-success">Mettre en file</button></div>
</form></div></section>
<section class="row g-3 mb-4">
<div class="col-md-4"><div class="card"><div class="card-body"><div class="text-secondary">Machines</div><div class="fs-3"><?= count($machines) + ($localSnapshot['ok'] ? 1 : 0) ?></div></div></div></div>
<div class="col-md-4"><div class="card"><div class="card-body"><div class="text-secondary">Projets</div><div class="fs-3"><?= count($projectRows) ?></div></div></div></div>
<div class="col-md-4"><div class="card"><div class="card-body"><div class="text-secondary">Jobs incertains</div><div class="fs-3 text-warning"><?= $counts['uncertain'] ?? 0 ?></div></div></div></div>
</section>
<?php if ($invitation !== null): ?><div class="alert alert-warning"><strong>Jeton d'enrôlement (affiché une seule fois)</strong><p class="mb-1">Expire à <?= e($invitation['expires_at']) ?> UTC. Ne le transmettez que par un canal sûr.</p><code class="user-select-all text-break"><?= e($invitation['token']) ?></code><?php if ($bootstrapCertHash !== false): ?><p class="mt-3 mb-1">Certificat TLS épinglé (SHA-256 du fichier PEM) :</p><code class="user-select-all text-break"><?= e($bootstrapCertHash) ?></code><p class="mt-2 mb-1">Installation du client (commande unique, jeton à usage unique) :</p>
<div class="position-relative">
<button type="button" id="copy-enrollment-command" class="btn btn-sm btn-outline-light position-absolute top-0 end-0 m-2" title="Copier la commande" aria-label="Copier la commande"><i class="fa-regular fa-copy" aria-hidden="true"></i></button>
<pre id="enrollment-command" class="user-select-all text-wrap p-3 pe-5 bg-dark text-light">sudo -v &amp;&amp; { tmp=$(mktemp) &amp;&amp; curl --proto '=https' --tlsv1.2 --fail --silent --show-error --location 'https://raw.githubusercontent.com/Cowprod/devMcp/5863a1e4552eb72edd000b9d992884da50abf138/deploy/distributed/install-agent.sh' -o "$tmp" &amp;&amp; { printf '%s\n' '<?= e($invitation['token']) ?>' | sudo -n bash "$tmp" --server <?= e($bootstrapServer) ?> --cert-url <?= e($bootstrapServer) ?>/devmcp-ca.crt --cert-sha256 <?= e($bootstrapCertHash) ?> --token-stdin; }; rc=$?; rm -f "$tmp"; test "$rc" -eq 0; }</pre>
</div>
<div id="copy-enrollment-feedback" class="small" role="status" aria-live="polite">Commande avec jeton à usage unique.</div>
<script>
document.getElementById('copy-enrollment-command').addEventListener('click', async function () {
    const feedback = document.getElementById('copy-enrollment-feedback');
    try {
        await navigator.clipboard.writeText(document.getElementById('enrollment-command').textContent.trim());
        feedback.textContent = 'Commande copiée : elle contient un jeton secret.';
    } catch (error) {
        feedback.textContent = 'Copie impossible : sélectionnez la commande.';
    }
});
</script>
<small>Attention : le jeton intégré peut être conservé dans l'historique shell. Exécution distante désactivée avant approbation.</small><?php else: ?><p class="mb-0 mt-2">Certificat du serveur indisponible pour la préparation de l'installation.</p><?php endif; ?></div><?php endif; ?>
<?php if ($error !== null): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<?php if (isset($_GET['created'])): ?><div class="alert alert-success">Enregistrement effectué.</div><?php endif; ?>
<section class="row g-3 mb-4">
<div class="col-lg-6"><div class="card"><div class="card-body">
<h2 class="h5">Ajouter une machine</h2>
<form method="post" action="/">
<input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
<input type="hidden" name="operation" value="machine.create">
<div class="input-group mb-2"><span class="input-group-text">Nom</span><input class="form-control" name="name" maxlength="128" required></div>
<div class="input-group mb-2"><span class="input-group-text">OS</span><select class="form-select" name="os"><option value="linux">Linux</option><option value="windows">Windows</option><option value="macos">macOS</option></select></div>
<div class="input-group mb-3"><span class="input-group-text">Architecture</span><select class="form-select" name="architecture"><option value="x86_64">x86_64</option><option value="aarch64">aarch64</option><option value="armv7">armv7</option></select></div>
<div class="btn-group"><button class="btn btn-success" type="submit">Ajouter</button></div>
</form><small class="text-secondary">Machine créée en attente, sans autorisation d'exécution.</small>
</div></div></div>
<div class="col-lg-6"><div class="card"><div class="card-body">
<h2 class="h5">Ajouter un projet</h2>
<form method="post" action="/">
<input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
<input type="hidden" name="operation" value="project.create">
<div class="input-group mb-2"><span class="input-group-text">Identifiant</span><input class="form-control" name="id" maxlength="64" required></div>
<div class="input-group mb-2"><span class="input-group-text">Description</span><input class="form-control" name="description" maxlength="255" required></div>
<div class="input-group mb-3"><span class="input-group-text">Dépôt HTTPS</span><input class="form-control" name="repository_url" type="url"></div>
<div class="btn-group"><button class="btn btn-success" type="submit">Ajouter</button></div>
</form></div></div></div>
</section>
<section class="card mb-4"><div class="card-body">
<h2 class="h5">Affecter un projet à une machine</h2>
<form method="post" action="/">
<input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
<input type="hidden" name="operation" value="deployment.create">
<div class="input-group mb-2"><span class="input-group-text">Projet</span><select class="form-select" name="project_id" required><?php foreach ($projects as $project): ?><option value="<?= e($project['id']) ?>"><?= e($project['id']) ?></option><?php endforeach; ?></select></div>
<div class="input-group mb-2"><span class="input-group-text">Machine</span><select class="form-select" name="machine_id" required><?php foreach ($machines as $machine): ?><option value="<?= e($machine['id']) ?>"><?= e($machine['name']) ?></option><?php endforeach; ?></select></div>
<div class="input-group mb-3"><span class="input-group-text">Répertoire absolu</span><input class="form-control" name="workspace_path" placeholder="/home/user/project" maxlength="1024" required></div>
<div class="btn-group"><button class="btn btn-success" type="submit" <?= !$projects || !$machines ? 'disabled' : '' ?>>Associer (exécution désactivée)</button></div>
</form><small class="text-secondary">Association enregistrée sans démarrage de jobs. Le répertoire sera vérifié sur l'agent avant toute activation.</small>
</div></section>
<h2 class="h5" id="android-adb">Android / ADB sur touchdeck</h2>
<div class="card mb-4"><div class="card-body">
<?php if (!$androidSnapshot['ok']): ?>
<div class="alert alert-warning">Inventaire ADB indisponible. Le service de publication doit être installé sur touchdeck.</div>
<?php else: ?>
<p class="small text-secondary mb-2">Dernier contrôle : <?= e($androidSnapshot['generated_at_utc']) ?> UTC.
<span class="badge <?= $adbFresh ? 'bg-success' : 'bg-warning' ?>"><?= $adbFresh ? 'Actualisé' : 'Périmé' ?></span>
<?php if (!$androidSnapshot['adb_available']): ?> · ADB indisponible sur l'hôte.<?php endif; ?>
</p>
<?php if (isset($_GET['adb_pending'])): ?><div class="alert alert-info">Capture demandée. Actualiser cette page après le prochain passage du service ADB.</div><?php endif; ?>
<div class="table-responsive"><table class="table table-sm table-striped table-hover">
<thead><tr><th>Actions</th><th>Série</th><th>État ADB</th><th>Modèle</th><th>Android</th><th>Batterie</th><th>Stockage libre</th><th>Capture précédente</th></tr></thead>
<tbody>
<?php foreach ($androidSnapshot['devices'] as $device): ?>
<?php $serial = $device['serial']; $shot = AndroidSnapshot::SCREENSHOT_DIRECTORY . '/' . $serial . '.png';
$pending = glob(AndroidSnapshot::REQUEST_DIRECTORY . '/*-' . $serial . '.req') ?: []; ?>
<tr><td>
<form method="post" action="/" class="d-inline">
<input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
<input type="hidden" name="operation" value="android.screenshot.request">
<input type="hidden" name="device" value="<?= e($serial) ?>">
<button type="submit" class="btn btn-sm btn-success" title="Capture d'écran" <?= (!$adbFresh || $device['state'] !== 'device') ? 'disabled' : '' ?>><i class="fa-solid fa-camera"></i> Capture</button>
</form></td>
<td><code><?= e($serial) ?></code></td>
<td><span class="badge <?= $device['state'] === 'device' && $adbFresh ? 'bg-success' : 'bg-secondary' ?>"><?= e($device['state']) ?></span></td>
<td><?= e($device['model'] ?: $device['product']) ?></td>
<td><?= e($device['android_version'] ?: '—') ?></td>
<td><?= $device['battery_percent'] === null ? '—' : e($device['battery_percent']) . '%' ?></td>
<td><?= $device['storage_free_gb'] === null ? '—' : e($device['storage_free_gb']) . ' Go' ?></td>
<td><?php if ($pending): ?><span class="badge bg-warning">En attente</span><?php endif; ?>
<?php if (is_file($shot) && !is_link($shot)): ?><button type="button" class="btn btn-link p-0 adb-preview" data-url="/?adb_image=<?= rawurlencode($serial) ?>" data-serial="<?= e($serial) ?>" title="Agrandir la capture"><img src="/?adb_image=<?= rawurlencode($serial) ?>" alt="Capture de <?= e($serial) ?>" loading="lazy" style="max-width:72px;max-height:100px;object-fit:contain;border-radius:4px"></button><div class="small"><?= e(gmdate('d/m H:i', (int) filemtime($shot))) ?> UTC</div><?php elseif (is_file(AndroidSnapshot::SCREENSHOT_DIRECTORY . '/' . $serial . '.error')): ?><span class="badge bg-danger">Échec</span><?php else: ?>—<?php endif; ?></td></tr>
<?php endforeach; ?>
<?php if (!$androidSnapshot['devices']): ?><tr><td colspan="8">Aucun appareil ADB détecté au dernier contrôle.</td></tr><?php endif; ?>
</tbody></table></div>
<?php endif; ?>
</div></div>
<h2 class="h5">Machines</h2>
<div class="table-responsive mb-4"><table class="table table-sm table-striped table-hover">
<thead><tr><th>Machine</th><th>OS</th><th>Architecture</th><th>État</th><th>Connexion</th><th>Version agent</th><th>Dernier contact (UTC)</th><th>Enrôlement</th></tr></thead><tbody>
<?php if ($localSnapshot['ok']): ?>
<tr><td><?= e($localSnapshot['host']['name']) ?></td><td><?= e($localSnapshot['host']['os']) ?></td>
<td><?= e($localSnapshot['host']['architecture']) ?></td><td><span class="badge bg-info">Local</span></td>
<td><span class="badge <?= $localFresh ? 'bg-success' : 'bg-warning' ?>"><?= $localFresh ? 'Hôte local' : 'Instantané périmé' ?></span></td><td>—</td>
<td><?= e($localSnapshot['generated_at_utc']) ?></td><td>Sans agent distant</td></tr>
<?php endif; ?>
<?php foreach ($machines as $machine): ?>
<tr><td><?= e($machine['name']) ?></td><td><?= e($machine['os_family']) ?></td><td><?= e($machine['architecture']) ?></td><td><?= e($machine['status']) ?></td><td><?php $seen = $machine['last_seen_at'] ?? null; $age = $seen ? time() - strtotime((string) $seen . ' UTC') : null; $online = $age !== null && $age >= -30 && $age <= 180; ?><span class="badge <?= $online ? 'bg-success' : 'bg-secondary' ?>"><?= $online ? 'En ligne' : 'Hors ligne' ?></span></td><td><?= e($machine['agent_version'] ?: 'Non renseignée') ?></td><td><?= e($seen ?: 'Jamais') ?></td><td><?php if ($machine['status'] === 'pending'): ?><form method="post" action="/"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="operation" value="enrollment.issue"><input type="hidden" name="machine_id" value="<?= e($machine['id']) ?>"><button class="btn btn-sm btn-success" type="submit">Générer un jeton</button></form><?php endif; ?><?php if ($machine['status'] === 'pending' && !empty($config['distributed_execution_approved'])): ?><form method="post" action="/" class="mt-1"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="operation" value="machine.approve"><input type="hidden" name="machine_id" value="<?= e($machine['id']) ?>"><button class="btn btn-sm btn-warning" type="submit">Approuver</button></form><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<h2 class="h5">Projets (locaux et distribués)</h2>
<?php if (!$localSnapshot['ok']): ?>
<div class="alert alert-warning">Catalogue local indisponible : <?= e($localSnapshot['reason']) ?>. Les projets distribués restent visibles. Aucun accès direct à la configuration privée depuis le serveur web.</div>
<?php else: ?>
<p class="small text-secondary">Catalogue local publié le <?= e($localSnapshot['generated_at_utc']) ?> UTC
<span class="badge <?= $localFresh ? 'bg-success' : 'bg-warning' ?>"><?= $localFresh ? 'Actualisé' : 'Périmé' ?></span>.
Les projets locaux sont consultables ici en lecture seule ; leur configuration et leurs actions restent sous le contrôle de devMcp.</p>
<?php endif; ?>
<div class="table-responsive mb-4"><table class="table table-sm table-striped table-hover">
<thead><tr><th>Identifiant</th><th>Description</th><th>Origine</th><th>Workspace local</th><th>Actions locales</th><th>Dépôt</th></tr></thead><tbody>
<?php foreach ($projectRows as $project): ?>
<tr><td><?= e($project['id']) ?></td><td><?= e($project['description']) ?></td>
<td><span class="badge <?= $project['origin'] === 'Local' ? 'bg-success' : ($project['origin'] === 'Distribué' ? 'bg-info' : 'bg-warning') ?>"><?= e($project['origin']) ?></span></td>
<td><code><?= e($project['workspace'] ?: '—') ?></code></td>
<td><?php if ($project['actions']): ?><details><summary><?= count($project['actions']) ?> actions</summary><div class="small text-break"><?= e(implode(', ', $project['actions'])) ?></div></details><?php else: ?>—<?php endif; ?></td>
<td><?= e($project['repository_url'] ?: '—') ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<h2 class="h5">Affectations</h2>
<div class="table-responsive mb-4"><table class="table table-sm table-striped table-hover"><thead><tr><th>Projet</th><th>Machine</th><th>Répertoire</th><th>Exécution</th><th>Diagnostic</th><th>Branche</th><th>Commit</th><th>Contrôle UTC</th></tr></thead><tbody>
<?php foreach ($deployments as $deployment): ?><tr><td><?= e($deployment['project_id']) ?></td><td><?= e($deployment['machine_name']) ?></td><td><code><?= e($deployment['workspace_path']) ?></code></td><td><span class="badge <?= $deployment['enabled'] ? 'bg-warning' : 'bg-secondary' ?>"><?= $deployment['enabled'] ? 'Activée' : 'Désactivée' ?></span></td><td><?= e($deployment['diagnostic_status'] ?? 'En attente') ?></td><td><?= e($deployment['diagnostic_branch'] ?? '—') ?></td><td><code><?= e($deployment['diagnostic_commit'] ?? '—') ?></code></td><td><?= e($deployment['diagnostic_at'] ?? 'Jamais') ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<h2 class="h5">Derniers jobs</h2>
<div class="table-responsive"><table class="table table-sm table-striped table-hover">
<thead><tr><th>Création UTC</th><th>Machine</th><th>Projet</th><th>Action</th><th>État</th></tr></thead><tbody>
<?php foreach ($jobs as $job): ?>
<tr><td><?= e($job['created_at']) ?></td><td><?= e($job['machine_name']) ?></td><td><?= e($job['project_id']) ?></td><td><?= e($job['action_id']) ?></td><td><span class="badge text-bg-secondary"><?= e($job['status']) ?></span></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</main>
<dialog id="adb-dialog" aria-label="Capture Android" style="max-width:96vw;max-height:95vh;padding:1rem;border-radius:10px;background:#222;color:white"><div class="d-flex justify-content-between align-items-center gap-3 mb-2"><strong id="adb-dialog-title">Capture Android</strong><button type="button" id="adb-close" class="btn btn-sm btn-secondary">Fermer</button></div><img id="adb-dialog-img" alt="Capture agrandie" style="display:block;max-width:90vw;max-height:75vh;object-fit:contain;margin:auto"><div class="mt-3"><a id="adb-download" class="btn btn-success" download>Télécharger le PNG</a></div></dialog>
<script>
(function () {
 const dialog=document.getElementById('adb-dialog'), img=document.getElementById('adb-dialog-img'), download=document.getElementById('adb-download');
 document.querySelectorAll('.adb-preview').forEach(function (button) {
   button.addEventListener('click',function () {
     const url=button.dataset.url, serial=button.dataset.serial;
     img.src=url; download.href=url; download.download='capture-'+serial+'.png';
     document.getElementById('adb-dialog-title').textContent='Capture Android — '+serial;
     dialog.showModal();
   });
 });
 document.getElementById('adb-close').addEventListener('click',function () {dialog.close();});
 dialog.addEventListener('click',function (event) {if(event.target===dialog)dialog.close();});
 dialog.addEventListener('close',function () {img.removeAttribute('src');});
})();
</script>
</body></html>
