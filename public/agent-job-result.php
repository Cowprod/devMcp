<?php
declare(strict_types=1);
use Cowprod\DevMcp\Distributed\Registry\DatabaseConnection;
use Cowprod\DevMcp\Distributed\Security\AgentAuthenticator;
require dirname(__DIR__) . '/vendor/autoload.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    if (($_SERVER['HTTPS'] ?? '') !== 'on' || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== '/v1/agent/job-result'
        || strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'application/json') {
        throw new RuntimeException('transport');
    }
    $body = file_get_contents('php://input', false, null, 0, 70001);
    if (!is_string($body) || strlen($body) > 70000) {
        throw new RuntimeException('size');
    }
    $payload = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
    $buildStart = is_array($payload) && ($payload['queue']??null)==='mobile-build-start';
    $iosHeartbeat = is_array($payload) && in_array($payload['queue'] ?? null,['ios-heartbeat','mobile-heartbeat'],true);
    $iosUpload = is_array($payload) && in_array($payload['queue'] ?? null,['ios-artifact','mobile-artifact'],true);
    $projectQueue = is_array($payload) && ($payload['queue'] ?? null) === 'projects';
    if (!is_array($payload) || array_keys($payload) !== ($iosUpload ? ['protocol_version','queue','job_id','artifact','offset','data'] : (($projectQueue||$iosHeartbeat||$buildStart)
        ? ['protocol_version','queue','job_id','result'] : ['protocol_version','job_id','result']))
        || $payload['protocol_version'] !== 1
        || !is_string($payload['job_id']) || !preg_match('/^[a-f0-9]{32}$/D', $payload['job_id'])
        || (!$iosUpload && !is_array($payload['result']))) {
        throw new RuntimeException('payload');
    }
    $headers = [];
    foreach (getallheaders() as $key => $value) {
        $headers[strtolower($key)] = $value;
    }
    $machineId = $headers['x-devmcp-machine'] ?? '';
    $authorization = $headers['authorization'] ?? '';
    if (!is_string($machineId) || !preg_match('/^[a-f0-9]{32}$/D', $machineId)
        || !is_string($authorization) || !preg_match('/^Bearer ([a-f0-9]{64})$/D', $authorization, $matches)) {
        throw new RuntimeException('identity');
    }
    $configPath = getenv('DEVMCP_DISTRIBUTED_CONFIG');
    if (!is_string($configPath) || !is_file($configPath)) {
        throw new RuntimeException('config');
    }
    $config = require $configPath;
    $db = DatabaseConnection::connect((string) ($config['dsn'] ?? ''),
        (string) ($config['writer_username'] ?? ''), (string) ($config['writer_password'] ?? ''));
    (new AgentAuthenticator($db))->authenticate($machineId, $matches[1], true);
    if($buildStart){
        (new AgentAuthenticator($db))->authenticate($machineId,$matches[1]);
        if($payload['result']!==['starting'=>true])throw new RuntimeException('Invalid build start');
        $query=$db->prepare("SELECT j.project_id,j.operation FROM dm_remote_project_job j JOIN dm_remote_project p ON p.project_id=j.project_id WHERE j.id=? AND j.machine_id=? AND j.state='claimed' AND j.operation IN ('ios_build','android_build') AND p.machine_id=j.machine_id");
        $query->execute([$payload['job_id'],$machineId]);$job=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($job))throw new RuntimeException('Unknown claimed build');
        $count=(new \Cowprod\DevMcp\Project\IosArtifactStore())->discardPrevious($machineId,$job['project_id'],$job['operation']==='android_build'?'android':'ios',$payload['job_id']);
        echo json_encode(['protocol_version'=>1,'ok'=>true,'removed'=>$count],JSON_THROW_ON_ERROR);exit;
    }
    if($iosHeartbeat){
        (new AgentAuthenticator($db))->authenticate($machineId,$matches[1]);
        if($payload['result']!==['running'=>true])throw new RuntimeException('Invalid iOS heartbeat');
        $query=$db->prepare("UPDATE dm_remote_project_job SET claimed_at=UTC_TIMESTAMP(6),expires_at=DATE_ADD(UTC_TIMESTAMP(6),INTERVAL 10 MINUTE) WHERE id=? AND machine_id=? AND state='claimed' AND operation IN ('ios_build','android_build')");
        $query->execute([$payload['job_id'],$machineId]);
        if($query->rowCount()!==1)throw new RuntimeException('iOS build lease lost');
        echo '{"protocol_version":1,"ok":true}';exit;
    }
    if($iosUpload){
        (new AgentAuthenticator($db))->authenticate($machineId,$matches[1]);
        $query=$db->prepare("SELECT j.project_id,j.operation,j.request_json FROM dm_remote_project_job j JOIN dm_machine m ON m.id=j.machine_id JOIN dm_remote_project p ON p.project_id=j.project_id WHERE j.id=? AND j.machine_id=? AND j.state='claimed' AND j.operation IN ('ios_build','android_build') AND m.os_family='macos' AND p.machine_id=j.machine_id");
        $query->execute([$payload['job_id'],$machineId]);$job=$query->fetch(PDO::FETCH_ASSOC);
        if(!is_array($job))throw new RuntimeException('Unknown mobile build job');
        $request=json_decode($job['request_json'],true,32,JSON_THROW_ON_ERROR);
        $target=$payload['artifact']['target']??null;
        if(($payload['artifact']['revision']??null)!==($request['package']['revision']??null)
            ||($job['operation']==='android_build'?$target!=='android':!in_array($target,['device','simulator'],true)))throw new RuntimeException('Artifact does not match the claimed build');
        $project=$job['project_id'];
        $upload=(new \Cowprod\DevMcp\Project\IosArtifactStore())->receive($machineId,$project,$payload);
        echo json_encode(['protocol_version'=>1,'ok'=>true,'upload'=>$upload],JSON_THROW_ON_ERROR);exit;
    }
    if ($projectQueue) {
        (new \Cowprod\DevMcp\Project\RemoteProjectQueue($db))->finish($machineId, $payload['job_id'], $payload['result']);
        echo '{"protocol_version":1,"ok":true}';
        exit;
    }
    $result = json_encode($payload['result'], JSON_THROW_ON_ERROR);
    if (strlen($result) > 65536) {
        throw new RuntimeException('result_size');
    }
    $statement = $db->prepare("UPDATE dm_remote_readonly_job SET state = ?, result_json = ?, finished_at = UTC_TIMESTAMP(6) WHERE id = ? AND machine_id = ? AND state = 'claimed'");
    $statement->execute([($payload['result']['ok'] ?? false) === true ? 'succeeded' : 'failed',
        $result, $payload['job_id'], $machineId]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('job_not_claimed');
    }
    echo '{"protocol_version":1,"ok":true}';
} catch (Throwable $error) {
    error_log('devmcp-job-result: ' . get_class($error) . ': ' . substr($error->getMessage(), 0, 150));
    http_response_code(400);
    echo '{"error":"invalid_job_result"}';
}
