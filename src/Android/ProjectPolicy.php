<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Android;
use Cowprod\DevMcp\Project\ProjectDefinition;
use Cowprod\DevMcp\Distributed\Admin\AndroidSnapshot;
final class ProjectPolicy
{
    /** Reuse the installation action's existing device policy, never a second allowlist. */
    public static function allowedDevices(ProjectDefinition $project,array $connected): array
    {
        try{$action=$project->getAction('android.install')->toPublicArray();}catch(\InvalidArgumentException){throw new DiagnosticException('device_policy_unavailable');}
        foreach($action['parameters'] as $parameter)if($parameter['id']==='device'){
            if($parameter['type']==='enum')return $parameter['values'];
            if($parameter['type']==='android_serial')return array_values(array_map(static fn($d)=>$d['serial'],array_filter($connected,static fn($d)=>$d['state']==='device'&&AndroidSnapshot::validSerial($d['serial']))));
        }
        throw new DiagnosticException('device_policy_unavailable');
    }
}
