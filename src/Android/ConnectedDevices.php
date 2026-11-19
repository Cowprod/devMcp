<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Android;

use Cowprod\DevMcp\Distributed\Admin\AndroidSnapshot;
use Symfony\Component\Process\Process;

/** ADB's current transport state is the sole device admission criterion. */
final class ConnectedDevices
{
    public static function requireDevice(string $serial,array $devices): array
    {
        if(!AndroidSnapshot::validSerial($serial))throw new DiagnosticException('invalid_device');
        $matches=array_values(array_filter($devices,static fn($d)=>($d['serial']??null)===$serial));
        if(count($matches)!==1)throw new DiagnosticException(count($matches)===0?'device_not_found':'ambiguous_device');
        if(($matches[0]['state']??null)!=='device')throw new DiagnosticException(match($matches[0]['state']??null){'unauthorized'=>'device_not_authorized','offline'=>'device_offline',default=>'device_not_ready'});
        return $matches[0];
    }
    public static function inventory(): array
    {
        $process=new Process(['/usr/bin/adb','devices','-l'],null,null,null,5);$output='';$overflow=false;
        try{$process->run(static function($type,$chunk)use($process,&$output,&$overflow){
            if($type===Process::OUT)$output.=$chunk;
            $process->clearOutput();$process->clearErrorOutput();
            if(strlen($output)>262144){$overflow=true;$output='';$process->stop(0);}
        });}catch(\Throwable){throw new DiagnosticException('adb_inventory_unavailable');}
        if($overflow||!$process->isSuccessful()||!str_starts_with(ltrim($output),'List of devices attached'))throw new DiagnosticException('adb_inventory_unavailable');
        return AndroidSnapshot::parseDevices($output);
    }
    public static function assertAction(string $action,array $arguments,?callable $inventory=null): void
    {
        if(!str_starts_with($action,'android.'))return;
        $targets=array_intersect_key($arguments,['device'=>true,'serial'=>true]);if($targets===[])return;
        $devices=$inventory===null?self::inventory():$inventory();
        foreach($targets as $serial){if(!is_string($serial))throw new DiagnosticException('invalid_device');self::requireDevice($serial,$devices);}
    }
}
