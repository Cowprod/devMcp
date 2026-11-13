<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Distributed\Admin;
use Closure;
use PDO;
use RuntimeException;

/** Read the existing live catalogue service; no stale snapshot, shell, alternate source or credential forwarding. */
final class LiveProjectCatalogueClient
{
    public function __construct(private readonly PDO $db,private readonly string $hostname,private readonly ?Closure $transport=null) {}
    public function read(): array
    {
        $query=$this->db->prepare('SELECT port FROM dm_preview_port_reservation WHERE project_id = ? AND machine = ?');
        $query->execute(['devmcp-projects',$this->hostname]);
        $port=$query->fetchColumn();
        if ($port===false||!ctype_digit((string)$port)||(int)$port<1024||(int)$port>65535) throw new RuntimeException('Live catalogue port unavailable');
        $url='http://127.0.0.1:'.(int)$port.'/?format=json';
        if ($this->transport!==null) $reply=($this->transport)($url);
        else {
            $curl=curl_init($url);
            curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,
                CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>25,CURLOPT_PROXY=>'',CURLOPT_HTTPHEADER=>['Accept: application/json'],
                CURLOPT_WRITEFUNCTION=>static function($curl,string $chunk)use(&$body):int {
                    $body=($body??'').$chunk;return strlen($body)>2097152?0:strlen($chunk);
                }]);
            $body='';$ok=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
            $reply=['status'=>$status,'body'=>$ok===false?'':$body];
        }
        if (($reply['status']??null)!==200||!is_string($reply['body']??null)||strlen($reply['body'])>2097152) throw new RuntimeException('Live catalogue HTTP unavailable');
        $data=json_decode($reply['body'],true,32,JSON_THROW_ON_ERROR);
        if (!is_array($data)||($data['schema_version']??null)!==1||!is_string($data['generated_at_utc']??null)
            ||!is_array($data['projects']??null)||!array_is_list($data['projects'])||count($data['projects'])>1000) throw new RuntimeException('Invalid live catalogue');
        $stamp=strtotime($data['generated_at_utc']);
        if ($stamp===false||$stamp<time()-60||$stamp>time()+30) throw new RuntimeException('Stale live catalogue');
        $seen=[];
        foreach ($data['projects'] as $row) {
            if (!is_array($row)||!is_string($row['id']??null)||!preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D',$row['id'])||isset($seen[$row['id']])) throw new RuntimeException('Invalid catalogue identity');
            foreach (['description','machine','kind','provisioning_state','http','status_checked_at_utc'] as $key) if (!is_string($row[$key]??null)) throw new RuntimeException('Invalid catalogue field');
            foreach (['platforms','mobile_configuration','mobile_builds','artifacts'] as $key) if (!is_array($row[$key]??null)) throw new RuntimeException('Invalid mobile catalogue');
            if (!array_key_exists('url',$row)||!array_key_exists('github',$row)) throw new RuntimeException('Missing catalogue URL');
            foreach (['url','github'] as $key) {
                $url=$row[$key];if($url===null)continue;
                $parts=is_string($url)?parse_url($url):false;
                if(!is_array($parts)||!filter_var($url,FILTER_VALIDATE_URL)||!in_array($parts['scheme']??null,['http','https'],true)||isset($parts['user'])||isset($parts['pass']))throw new RuntimeException('Invalid catalogue URL');
            }
            $seen[$row['id']]=true;
        }
        return $data;
    }
}
