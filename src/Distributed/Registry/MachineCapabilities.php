<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Distributed\Registry;

use InvalidArgumentException;
use PDO;
use RuntimeException;

/** Declared machine capabilities, independent from installed tool diagnostics. */
final class MachineCapabilities
{
    public const LABELS = ['hosting'=>'Hébergement web (statique, PHP, Node)', 'cordova_android'=>'Builds Cordova Android', 'cordova_ios'=>'Builds iOS / Cordova iOS'];
    public function __construct(private readonly PDO $db) {}

    public static function validate(string $os,array $values): array
    {
        if(count($values)!==count(self::LABELS)||array_diff(array_keys($values),array_keys(self::LABELS)))throw new InvalidArgumentException('Les trois capacités doivent être déclarées');
        foreach($values as $value)if(!is_bool($value))throw new InvalidArgumentException('Capacité machine invalide');
        if($values['cordova_ios']&&$os!=='macos')throw new InvalidArgumentException('La compilation iOS nécessite macOS');
        if($values['cordova_android']&&!in_array($os,['macos','linux'],true))throw new InvalidArgumentException('Agent Android disponible uniquement sur Linux et macOS');
        return array_replace(array_fill_keys(array_keys(self::LABELS),false),$values);
    }

    public static function fromPost(array $post): array
    {
        $values=[];
        foreach(self::LABELS as $key=>$label){
            $value=$post[$key]??null;
            if(!in_array($value,['0','1'],true))throw new InvalidArgumentException('Déclaration de capacités incomplète');
            $values[$key]=$value==='1';
        }
        return $values;
    }

    public function all(): array
    {
        $rows=$this->db->query('SELECT machine_key,hosting,cordova_android,cordova_ios FROM dm_machine_capability')->fetchAll(PDO::FETCH_ASSOC);
        $result=[];
        foreach($rows as $row){$key=$row['machine_key'];unset($row['machine_key']);$result[$key]=array_map(static fn($v)=>(bool)$v,$row);}
        return $result;
    }

    public function get(string $key): array
    {
        $values=$this->all()[$key]??null;
        if($values===null)throw new RuntimeException('Capacités machine non déclarées dans l’administration');
        return $values;
    }

    /** Caller owns the registry transaction and audit event. */
    public function save(string $key,string $os,array $values): void
    {
        if($key!=='local'&&!preg_match('/^[a-f0-9]{32}$/D',$key))throw new InvalidArgumentException('Identifiant machine invalide');
        $values=self::validate($os,$values);
        $check=$this->db->prepare('SELECT machine_key FROM dm_machine_capability WHERE machine_key=?');$check->execute([$key]);
        $sql=$check->fetchColumn()===false
            ?'INSERT INTO dm_machine_capability (hosting,cordova_android,cordova_ios,machine_key) VALUES (?,?,?,?)'
            :'UPDATE dm_machine_capability SET hosting=?,cordova_android=?,cordova_ios=? WHERE machine_key=?';
        $this->db->prepare($sql)->execute([...(array_map(static fn($v)=>(int)$v,array_values($values))),$key]);
    }

    public function require(string $key,string $capability): void
    {
        if(!isset(self::LABELS[$capability])||!$this->get($key)[$capability])throw new RuntimeException('Capacité désactivée pour cette machine : '.$capability);
    }
}
