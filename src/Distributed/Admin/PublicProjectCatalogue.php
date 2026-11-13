<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Distributed\Admin;
use RuntimeException;

/** Display-only view of the exact inventory returned by MCP project_list. */
final class PublicProjectCatalogue
{
    public static function live(array $config,bool $probeLocalPreviews=true): array
    {
        $remote=\Cowprod\DevMcp\Mcp\RemoteTools::fromConfig($config);
        if($remote===null||!$remote->sqlProjectConfiguration()->isReady())throw new RuntimeException('The live distributed registry is unavailable');
        $servingProject=getenv('DEVMCP_PROJECT_ID');
        $servingProject=is_string($servingProject)&&preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D',$servingProject)?$servingProject:null;
        $inventory=\Cowprod\DevMcp\Runtime\RuntimeFactory::fromConfig($config,dirname(__DIR__,3).'/var')->tools->projectInventory($probeLocalPreviews,$servingProject);
        $host=gethostname();$ip=$config['preview_zerotier_ipv4']??'';
        // Same explicit lab address as the existing MCP preview probes.
        if($ip===''&&$host==='touchdeck')$ip='192.168.194.189';
        return self::export($inventory,$host,$ip);
    }

    public static function export(array $inventory,string $localHost,string $localIpv4): array
    {
        if(!is_array($inventory['projects']??null)||!array_is_list($inventory['projects']))throw new RuntimeException('Complete project inventory required');
        $rows=[];$seen=[];
        foreach($inventory['projects'] as $project){
            $id=$project['id']??null;
            if(!is_string($id)||!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D',$id)||isset($seen[$id]))throw new RuntimeException('Invalid or duplicate catalogue project');
            $seen[$id]=true;
            $machine=(string)($project['machine']??'');
            $kind=($project['target']??'')==='remote'||($machine!==''&&$machine!==$localHost)?'remote':(($machine===$localHost||!empty($project['workspace']))?'local':'pending');
            if($kind==='local'&&$machine==='')$machine=$localHost;
            $url=null;$port=$project['preview_port']??null;
            if($kind==='remote'&&is_string($project['url']??null)){
                // Keep the URL reported by the agent; never combine its port with the central host.
                $url=$project['url'];
                $parts=parse_url($url);
                if(!filter_var($url,FILTER_VALIDATE_URL)||!in_array($parts['scheme']??null,['http','https'],true)||isset($parts['user'])||isset($parts['pass']))throw new RuntimeException('Invalid agent project URL');
            }elseif($kind==='local'&&is_int($port)&&$port>=1024&&$port<=65535&&filter_var($localIpv4,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){
                $url='http://'.$localIpv4.':'.$port.'/';
            }
            $repository=$project['repository']??'';
            $http=$project['preview_http_zerotier_host']??'unknown';
            if(!in_array($http,['http','tcp','offline','not_checked','unknown'],true))throw new RuntimeException('Invalid observed HTTP state');
            $rows[]=[
                'id'=>$id,'description'=>(string)($project['description']??''),'machine'=>$machine,'kind'=>$kind,
                'http'=>$http,'status_checked_at_utc'=>(string)($project['preview_http_checked_at_utc']??''),
                'provisioning_state'=>(string)($project['provisioning_state']??''),'url'=>$url,
                'github'=>is_string($repository)&&preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~D',$repository)?'https://github.com/'.$repository:null,
                'artifacts'=>[],
            ];
        }
        usort($rows,static fn($a,$b)=>strcmp($a['id'],$b['id']));
        return ['schema_version'=>1,'generated_at_utc'=>gmdate('Y-m-d\TH:i:s\Z'),'projects'=>$rows];
    }

}
