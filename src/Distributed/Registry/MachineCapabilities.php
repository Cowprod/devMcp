<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Distributed\Registry;

use Cowprod\DevMcp\Project\CowprodPhpSocle;
use InvalidArgumentException;
use LogicException;
use PDO;
use RuntimeException;

/** Declared machine capabilities, independent from installed tool diagnostics. */
final class MachineCapabilities
{
    public const LABELS = ['hosting'=>'Hébergement web (statique, PHP, Node)', 'cordova_android'=>'Builds Cordova Android', 'cordova_ios'=>'Builds iOS / Cordova iOS'];
    public function __construct(
        private readonly PDO $db,
        private readonly array $aConfig = [],
        private readonly ?string $sActeur = null,
    ) {}

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
        if (array_key_exists('cowprod_machine_capabilities', $this->aConfig)) {
            $this->saveCowprod($key, $values);
            return;
        }
        $check=$this->db->prepare('SELECT machine_key FROM dm_machine_capability WHERE machine_key=?');$check->execute([$key]);
        $sql=$check->fetchColumn()===false
            ?'INSERT INTO dm_machine_capability (hosting,cordova_android,cordova_ios,machine_key) VALUES (?,?,?,?)'
            :'UPDATE dm_machine_capability SET hosting=?,cordova_android=?,cordova_ios=? WHERE machine_key=?';
        $this->db->prepare($sql)->execute([...(array_map(static fn($v)=>(int)$v,array_values($values))),$key]);
    }

    private function saveCowprod(string $sMachine, array $aValeurs): void
    {
        if (!$this->db->inTransaction())
            throw new LogicException('Transaction de capacités requise');

        $sActeur = CowprodPhpSocle::chargerPourCatalogue($this->aConfig['cowprod_machine_capabilities'], $this->sActeur);
        if (trim($sActeur) === '' || mb_strlen($sActeur) > 191 || preg_match('/[\x00-\x1f]/', $sActeur))
            throw new InvalidArgumentException('Signature des capacités indisponible');

        $oConn = $this->db;
        $aMere = oRs('', __DIR__.'/machineCapability/modMachineCapability.sql',
            'MCA_CH_MACHINE='.urlencode(prepString2Update($sMachine)), false, '', $oConn)[0] ?? null;
        if ($aMere !== null && array_map('boolval', array_intersect_key($aMere, $aValeurs)) === $aValeurs)
            return;

        $bSession = array_key_exists('_SESSION', $GLOBALS);
        $aSession = $_SESSION ?? [];
        try {
            $_SESSION = ['logged'=>'1', 'sUser'=>$sActeur] + $aSession;
            if ($aMere === null) {
                $nId = getIdConPdo('T_MACHINECAPABILITY', 'MCA_CH_MACHINE', $sMachine, $oConn);
                $sCreation = ', MCA_DT_CREATION=NOW(6), MCA_CH_CREATION='.prepString2Update(sSignature());
            } else {
                $nId = $aMere['MCA_N_ID'];
                historiseTable('T_MACHINECAPABILITY', 'MCA', $nId, $oConn);
                $sCreation = '';
            }
            $oConn->exec('UPDATE T_MACHINECAPABILITY SET MCA_BL_HOSTING='.prepNum2Update((int)$aValeurs['hosting'])
                .', MCA_BL_CORDOVA_ANDROID='.prepNum2Update((int)$aValeurs['cordova_android'])
                .', MCA_BL_CORDOVA_IOS='.prepNum2Update((int)$aValeurs['cordova_ios'])
                .$sCreation.' WHERE MCA_N_ID='.prepNum2Update($nId));
        } finally {
            if ($bSession)
                $_SESSION = $aSession;
            else
                unset($_SESSION);
        }
    }

    public function require(string $key,string $capability): void
    {
        if(!isset(self::LABELS[$capability])||!$this->get($key)[$capability])throw new RuntimeException('Capacité désactivée pour cette machine : '.$capability);
    }
}
