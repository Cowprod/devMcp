<?php

declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Admin\DashboardRepository;
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
session_start(['cookie_httponly' => true, 'cookie_secure' => true, 'cookie_samesite' => 'Strict', 'use_strict_mode' => true]);
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
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
<section class="row g-3 mb-4">
<div class="col-md-4"><div class="card"><div class="card-body"><div class="text-secondary">Machines</div><div class="fs-3"><?= count($machines) ?></div></div></div></div>
<div class="col-md-4"><div class="card"><div class="card-body"><div class="text-secondary">Projets</div><div class="fs-3"><?= count($projects) ?></div></div></div></div>
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
<h2 class="h5">Machines</h2>
<div class="table-responsive mb-4"><table class="table table-sm table-striped table-hover">
<thead><tr><th>Machine</th><th>OS</th><th>Architecture</th><th>État</th><th>Connexion</th><th>Version agent</th><th>Dernier contact (UTC)</th><th>Enrôlement</th></tr></thead><tbody>
<?php foreach ($machines as $machine): ?>
<tr><td><?= e($machine['name']) ?></td><td><?= e($machine['os_family']) ?></td><td><?= e($machine['architecture']) ?></td><td><?= e($machine['status']) ?></td><td><?php $seen = $machine['last_seen_at'] ?? null; $age = $seen ? time() - strtotime((string) $seen . ' UTC') : null; $online = $age !== null && $age >= -30 && $age <= 180; ?><span class="badge <?= $online ? 'bg-success' : 'bg-secondary' ?>"><?= $online ? 'En ligne' : 'Hors ligne' ?></span></td><td><?= e($machine['agent_version'] ?: 'Non renseignée') ?></td><td><?= e($seen ?: 'Jamais') ?></td><td><?php if ($machine['status'] === 'pending'): ?><form method="post" action="/"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="operation" value="enrollment.issue"><input type="hidden" name="machine_id" value="<?= e($machine['id']) ?>"><button class="btn btn-sm btn-success" type="submit">Générer un jeton</button></form><?php endif; ?><?php if ($machine['status'] === 'pending' && !empty($config['distributed_execution_approved'])): ?><form method="post" action="/" class="mt-1"><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>"><input type="hidden" name="operation" value="machine.approve"><input type="hidden" name="machine_id" value="<?= e($machine['id']) ?>"><button class="btn btn-sm btn-warning" type="submit">Approuver</button></form><?php endif; ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<h2 class="h5">Projets</h2>
<div class="table-responsive mb-4"><table class="table table-sm table-striped table-hover">
<thead><tr><th>Identifiant</th><th>Description</th><th>Dépôt</th></tr></thead><tbody>
<?php foreach ($projects as $project): ?>
<tr><td><?= e($project['id']) ?></td><td><?= e($project['description']) ?></td><td><?= e($project['repository_url']) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
<h2 class="h5">Affectations</h2>
<div class="table-responsive mb-4"><table class="table table-sm table-striped table-hover"><thead><tr><th>Projet</th><th>Machine</th><th>Répertoire</th><th>Exécution</th></tr></thead><tbody>
<?php foreach ($deployments as $deployment): ?><tr><td><?= e($deployment['project_id']) ?></td><td><?= e($deployment['machine_name']) ?></td><td><code><?= e($deployment['workspace_path']) ?></code></td><td><span class="badge <?= $deployment['enabled'] ? 'bg-warning' : 'bg-secondary' ?>"><?= $deployment['enabled'] ? 'Activée' : 'Désactivée' ?></span></td></tr><?php endforeach; ?>
</tbody></table></div>
<h2 class="h5">Derniers jobs</h2>
<div class="table-responsive"><table class="table table-sm table-striped table-hover">
<thead><tr><th>Création UTC</th><th>Machine</th><th>Projet</th><th>Action</th><th>État</th></tr></thead><tbody>
<?php foreach ($jobs as $job): ?>
<tr><td><?= e($job['created_at']) ?></td><td><?= e($job['machine_name']) ?></td><td><?= e($job['project_id']) ?></td><td><?= e($job['action_id']) ?></td><td><span class="badge text-bg-secondary"><?= e($job['status']) ?></span></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</main>
</body></html>
