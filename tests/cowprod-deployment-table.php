<?php

declare(strict_types=1);

use Cowprod\DevMcp\Distributed\Admin\DashboardRepository;
use Cowprod\DevMcp\Distributed\Admin\RegistryEditor;
use Cowprod\DevMcp\Notification\FinishedJobs;
use Cowprod\DevMcp\Project\CowprodDeploymentRegistry;
use Cowprod\DevMcp\Project\DeploymentTableMigration;
use Cowprod\DevMcp\Project\ProjectRegistryMigration;

require dirname(__DIR__).'/vendor/autoload.php';

function verifier(bool $condition, string $message): void
{
    if (!$condition)
        throw new RuntimeException($message);
}

$dsn = getenv('COWPROD_DEPLOYMENT_TEST_DSN');
$socle = getenv('COWPROD_DEPLOYMENT_TEST_SOCLE');
if (!$dsn || !str_starts_with($dsn,'mysql:') || !$socle)
    throw new RuntimeException('Une base jetable et un socle généré sont requis');
$user = getenv('COWPROD_DEPLOYMENT_TEST_USER') ?: 'root';
$password = getenv('COWPROD_DEPLOYMENT_TEST_PASSWORD') ?: '';
$db = new PDO($dsn,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false]);
$base = 'cowprod_deployment_'.bin2hex(random_bytes(8));
$db->exec("CREATE DATABASE `".$base."` CHARACTER SET utf8mb4");
try {
    $db->exec("USE `".$base."`");
    foreach (glob(dirname(__DIR__).'/database/migrations/*.sql') as $file) {
        $number = substr(basename($file),0,3);
        if (!in_array($number,['001','002','004','005','006','008'],true))
            continue;
        $ddl = preg_replace('/^\h*--.*$/m','',file_get_contents($file));
        foreach (explode(';',$ddl) as $query)
            if (trim($query) !== '')
                $db->exec($query);
    }
    $machine = str_repeat('a',32);
    $id = str_repeat('b',32);
    $job = str_repeat('c',32);
    $db->exec("INSERT INTO dm_machine (id,name,os_family,architecture,status) VALUES ('$machine','Agent','linux','x86_64','active')");
    $db->exec("INSERT INTO dm_project (id,description) VALUES ('demo','Démo'),('nouveau','Nouveau')");
    $db->exec("INSERT INTO dm_deployment (id,machine_id,project_id,workspace_path)
        VALUES ('$id','$machine','demo','/srv/avant')");
    $db->exec("INSERT INTO dm_remote_readonly_job (id,deployment_id,machine_id,action_name,arguments_json)
        VALUES ('$job','$id','$machine','git.head','{}')");

    $projects = new ProjectRegistryMigration($db);
    $projects->preparer();
    $projects->activer();
    $migration = new DeploymentTableMigration($db);
    verifier($migration->preparer() === ['deployments'=>1] && $migration->preparer() === $migration->verifier(),
        'Reprise complète et idempotente');
    verifier($migration->activer() === ['deployments'=>1] && $projects->verifier() === ['projects'=>2],
        'Clés vers le nouveau registre et vers les déploiements');

    $settings = ['cowprod_deployments'=>[
        'socle_root'=>$socle,'mcp_actor'=>'service:fixture',
        'socle_revision'=>json_decode(file_get_contents($socle.'/socle.lock.json'),true,flags:JSON_THROW_ON_ERROR)['revision'],
    ]];
    $registry = CowprodDeploymentRegistry::configured($db,$settings,'web-admin:alice');
    $editor = new RegistryEditor($db,'web-admin:alice',deployments:$registry);
    $editor->updateDeploymentPath($id,'/srv/apres');
    $editor->updateDeploymentPath($id,'/srv/apres');
    verifier((int)$db->query("SELECT COUNT(*) FROM T_DEPLOYMENT WHERE DEP_N_MID IS NOT NULL
        AND DEP_CH_WORKSPACE_PATH='/srv/avant' AND DEP_CH_SUPPRESSION='web-admin:alice'")->fetchColumn() === 1, 'Historique unique et signé');
    try {
        $editor->removeDeployment($id);
        throw new LogicException('Retrait avec un travail en file accepté');
    } catch (InvalidArgumentException) {}

    // SELECT FOR UPDATE through the compatibility view must lock the true row.
    $db->beginTransaction();
    $db->query("SELECT id FROM dm_deployment WHERE id='$id' FOR UPDATE")->fetchAll();
    $other = new PDO($dsn.';dbname='.$base,$user,$password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $other->exec('SET SESSION innodb_lock_wait_timeout=1');
    try {
        $other->exec("UPDATE T_DEPLOYMENT SET DEP_BL_ENABLED=1 WHERE DEP_CH_MERE='$id'");
        throw new LogicException('Le verrou du déploiement a disparu à travers la vue');
    } catch (PDOException $error) {
        verifier(($error->errorInfo[1]??null) === 1205, 'Verrou sur la ligne source');
    }
    $db->rollBack();

    $db->exec("UPDATE dm_remote_readonly_job SET state='succeeded',finished_at=NOW() WHERE id='$job'");
    $editor->removeDeployment($id);
    verifier((int)$db->query("SELECT COUNT(*) FROM dm_deployment WHERE id='$id'")->fetchColumn() === 0,
        'Un déploiement retiré ne figure plus dans les lectures actives');
    verifier((int)$db->query("SELECT COUNT(*) FROM T_DEPLOYMENT WHERE DEP_CH_MERE='$id'
        AND DEP_DT_SUPPRESSION IS NOT NULL AND DEP_CH_SUPPRESSION='web-admin:alice'")->fetchColumn() === 1,
        'Retrait signé de la ligne mère');
    verifier((new DashboardRepository($db,true))->recentRemoteReadonlyJobs()[0]['deployment_id'] === $id,
        'Le tableau de bord retrouve un job historique');
    $notifications = iterator_to_array(FinishedJobs::remote($db,new DateTimeImmutable('-1 hour'), true),false);
    verifier(count(array_filter($notifications,static fn(array $row):bool=>$row['id']===$job)) === 1,
        'Le job historique reste visible aux notifications');
    $editor->assignDeployment('nouveau',$machine,'/srv/nouveau');
    verifier((int)$db->query("SELECT COUNT(*) FROM T_DEPLOYMENT WHERE DEP_CH_PROJECT_ID='nouveau'
        AND DEP_CH_CREATION='web-admin:alice'")->fetchColumn() === 1,'Création par le registre');
    verifier($migration->retour() === ['deployments'=>2] && $projects->verifier() === ['projects'=>2],
        'Retour avec les écritures récentes et les liens d’origine');
    verifier((int)$db->query("SELECT COUNT(*) FROM dm_deployment WHERE id='$id' AND enabled=0")->fetchColumn() === 1,
        'Le retour conserve le parent historique désactivé');
    verifier((int)$db->query('SELECT COUNT(*) FROM T_DEPLOYMENT WHERE DEP_DT_SUPPRESSION IS NOT NULL')->fetchColumn() === 2,
        'Historique et retrait conservés après le retour');
    echo 'Table des déploiements OK sur '.$db->getAttribute(PDO::ATTR_SERVER_VERSION).PHP_EOL;
} finally {
    if ($db->inTransaction())
        $db->rollBack();
    $db->exec("DROP DATABASE `".$base."`");
}
