<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Distributed\Admin;

use Closure;
use Cowprod\DevMcp\Distributed\Registry\MachineCapabilities;
use Cowprod\DevMcp\Distributed\Registry\RegistryRepository;
use Cowprod\DevMcp\Project\ProjectRuntimeCatalog;
use PDO;

/** Read projection of the existing registry and MCP, with an explicit public field allowlist. */
final class AdminReadRepository
{
    public function __construct(private readonly PDO $db, private readonly Closure $catalogue,
        private readonly array $localHost) {}

    public function snapshot(int $now): array
    {
        $registry = new RegistryRepository($this->db);
        $capabilities = (new MachineCapabilities($this->db))->all();
        $catalogue = ($this->catalogue)();
        $machines = [];
        foreach ($registry->machines() as $row) {
            $seen = $row['last_seen_at'] === null ? false : strtotime($row['last_seen_at'].' UTC');
            $machines[$row['id']] = [
                'id'=>$row['id'], 'name'=>$row['name'], 'hostname'=>null,
                'os'=>$row['os_family'], 'architecture'=>$row['architecture'],
                'agent_version'=>$row['agent_version'], 'status'=>$row['status'],
                'connection'=>$seen !== false && $seen >= $now-180 && $seen <= $now+30 ? 'online' : 'offline',
                'last_seen_at_utc'=>$seen === false ? null : gmdate('Y-m-d\TH:i:s\Z',$seen),
                'capabilities'=>$capabilities[$row['id']]??null, 'projects'=>[],
            ];
        }
        // The Web admin already models the central host separately, under capability key "local".
        $machines['local'] = ['id'=>'local','name'=>$this->localHost['name'],'hostname'=>$this->localHost['name'],
            'os'=>$this->localHost['os'],'architecture'=>$this->localHost['architecture'],
            'agent_version'=>null,'status'=>null,'connection'=>'online','last_seen_at_utc'=>null,
            'capabilities'=>$capabilities['local']??null,'projects'=>[]];
        $resolve = static function (?string $key) use (&$machines): ?string {
            if ($key === null || $key === '') return null;
            if (isset($machines[$key])) return $key;
            foreach ($machines as $id=>$m) if ($m['name']===$key) return $id;
            return null;
        };
        $projects = [];
        foreach ($registry->projects() as $row) {
            $repo = self::github($row['repository_url']);
            $projects[$row['id']] = ['id'=>$row['id'],'name'=>null,'description'=>$row['description'],
                'github'=>$repo,'kind'=>null,'provisioning_state'=>null,'deployment_state'=>null,'deployment_checked_at_utc'=>null,
                'runtime_type'=>null,'desired_state'=>null,'environment'=>null,'port'=>null,'url'=>null,
                'platforms'=>['web'=>null,'ios'=>null,'android'=>null],
                'mobile_configuration'=>null,'mobile_builds'=>null,'artifacts'=>[], 'assignments'=>[]];
        }
        foreach ($catalogue['projects'] as $row) {
            $projects[$row['id']] = array_replace($projects[$row['id']]??[], [
                'id'=>$row['id'],'name'=>null,'description'=>$row['description'],
                'github'=>$row['github']??($projects[$row['id']]['github']??null),
                'kind'=>$row['kind'],'provisioning_state'=>$row['provisioning_state']?:null,
                // HTTP observation is not an inferred deployment success.
                'deployment_state'=>$row['http'],'deployment_checked_at_utc'=>$row['status_checked_at_utc']?:null,
                'runtime_type'=>null,'desired_state'=>null,'environment'=>null,
                'url'=>$row['url'],'port'=>$row['url']===null?null:(parse_url($row['url'],PHP_URL_PORT)?:null),
                'platforms'=>array_intersect_key($row['platforms'],array_flip(['web','ios','android'])),
                'mobile_configuration'=>self::fields($row['mobile_configuration'],['known','revision','errors']),
                'mobile_builds'=>self::builds($row['mobile_builds']),
                'artifacts'=>array_map(static fn($a)=>self::fields($a,['platform','kind','job_id','version','bundle_id','revision','size','sha256','build_url','download_url','available']),$row['artifacts']),
                'assignments'=>[],
            ]);
            $machineId = $resolve($row['machine']);
            if ($machineId !== null) $projects[$row['id']]['assignments'][$machineId] = ['machine_id'=>$machineId,'sources'=>['inventory'],'deployment_id'=>null,'enabled'=>null];
        }
        foreach ($registry->deployments() as $row) {
            if (!isset($projects[$row['project_id']])) continue;
            $entry = $projects[$row['project_id']]['assignments'][$row['machine_id']]??['machine_id'=>$row['machine_id'],'sources'=>[]];
            $entry['sources'][] = 'deployment_registry'; $entry['deployment_id']=$row['id']; $entry['enabled']=(bool)$row['enabled'];
            $projects[$row['project_id']]['assignments'][$row['machine_id']]=$entry;
        }
        // Runtime choice and remote metadata are existing admin sources; never return config JSON or paths.
        foreach ((new ProjectRuntimeCatalog($this->db))->selections() as $id=>$runtime) {
            if (!isset($projects[$id])) continue;
            $projects[$id]['runtime_type']=$runtime['runtime_type'];
            $projects[$id]['desired_state']=$runtime['desired_state'];
        }
        foreach ($this->db->query('SELECT project_id, machine_id, metadata_json FROM dm_remote_project')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!isset($projects[$row['project_id']])) continue;
            $meta = $row['metadata_json']===null?[]:json_decode($row['metadata_json'],true,32,JSON_THROW_ON_ERROR);
            $projects[$row['project_id']]['runtime_type']=is_string($meta['runtime']??null)?$meta['runtime']:null;
            if (isset($machines[$row['machine_id']])) {
                $entry=$projects[$row['project_id']]['assignments'][$row['machine_id']]??['machine_id'=>$row['machine_id'],'sources'=>[],'deployment_id'=>null,'enabled'=>null];
                $entry['sources'][]='remote_project';
                $projects[$row['project_id']]['assignments'][$row['machine_id']]=$entry;
            }
        }
        foreach ($projects as $id=>&$project) {
            $project['assignments']=array_values($project['assignments']);
            foreach ($project['assignments'] as &$assignment) {
                $mid=$assignment['machine_id']; $assignment['machine_name']=$machines[$mid]['name'];
                $machines[$mid]['projects'][]=['id'=>$id,'name'=>$project['name'],'description'=>$project['description']];
            }
            unset($assignment);
        }
        unset($project);
        foreach ($machines as &$machine) {
            $machine['project_count']=count($machine['projects']);
            $machine['hosting_types']=$machine['capabilities']===null?null:($machine['capabilities']['hosting']?['static','php','node']:[]);
        }
        unset($machine);
        return ['machines'=>$machines,'projects'=>$projects];
    }
    private static function fields(array $data,array $keys): array { return array_intersect_key($data,array_flip($keys)); }
    private static function builds(array $data): array
    {
        $result=[];
        foreach (['ios','android'] as $platform) if (isset($data[$platform])) $result[$platform]=self::fields($data[$platform],['status','job_id','target','version','revision','created_at_utc','started_at_utc','finished_at_utc','available','availability']);
        return $result;
    }
    private static function github(?string $url): ?string
    {
        return is_string($url)&&preg_match('~^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:\.git)?$~D',$url)?$url:null;
    }
}
