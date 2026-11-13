<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;
use RuntimeException;
use Cowprod\DevMcp\Distributed\Admin\AndroidSnapshot;
final class AndroidInstallation
{
    public static function validate(array $record,string $project,string $device,array $connected): array
    {
        if(($record['project_id']??null)!==$project||($record['artifact']['target']??null)!=='android'||($record['artifact']['signed']??null)!==true)throw new RuntimeException('A signed Android build of this exact project is required');
        if(!AndroidSnapshot::validSerial($device))throw new RuntimeException('Explicit ADB serial required');
        $matches=array_values(array_filter($connected,static fn($row)=>($row['serial']??null)===$device));
        if(count($matches)!==1||$matches[0]['state']!=='device')throw new RuntimeException('The selected tablet must be connected and authorized');
        $file=$record['path']??'';
        if(!is_file($file)||is_link($file)||filesize($file)!==$record['artifact']['size']||hash_file('sha256',$file)!==$record['artifact']['sha256'])throw new RuntimeException('Published APK integrity mismatch');
        return $record['artifact'];
    }
    public static function packageMetadata(string $badging,array $artifact): array
    {
        if(!preg_match("/package: name='([A-Za-z0-9_.]+)' versionCode='([0-9]+)' versionName='([^']+)'/",$badging,$m)
            ||$m[1]!==$artifact['bundle_id']||$m[3]!==$artifact['version']||!str_contains($badging,'application-debuggable'))throw new RuntimeException('Only the verified debug package and version may be installed');
        return ['package'=>$m[1],'version_code'=>$m[2],'version'=>$m[3]];
    }
    public static function component(string $output,string $package): string
    {
        $lines=preg_split('/\r?\n/',trim($output));$component=end($lines);
        if(!is_string($component)||!preg_match('~^'.preg_quote($package,'~').'/[A-Za-z0-9_.$]+$~D',$component))throw new RuntimeException('App launcher activity could not be resolved');
        return $component;
    }
}
