<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Project;
use InvalidArgumentException;
/** Fixed instance configuration; environment values are never included in public projections. */
final class DeploymentConfiguration
{
    public static function validate(array $value): array
    {
        if(array_diff(array_keys($value),['port','variables','clear_variables']))throw new InvalidArgumentException('Unknown deployment configuration field');
        $port=$value['port']??null;
        if($port!==null&&(!is_int($port)||$port<12000||$port>19999))throw new InvalidArgumentException('Deployment port must be inside the approved hosting range 12000..19999');
        if(array_key_exists('clear_variables',$value)&&!is_bool($value['clear_variables']))throw new InvalidArgumentException('clear_variables must be a boolean');
        if(($value['clear_variables']??false)===true&&array_key_exists('variables',$value))throw new InvalidArgumentException('Choose either replacement variables or clear_variables, never both');
        $variables=$value['variables']??[];
        if(!is_array($variables)||count($variables)>64)throw new InvalidArgumentException('At most 64 environment variables are supported');
        foreach($variables as $name=>$text){
            if(!is_string($name)||!preg_match('/^[A-Z_][A-Z0-9_]{0,63}$/D',$name)||!is_string($text)||strlen($text)>4096||preg_match('/[\x00-\x1f\x7f]/',$text)
                ||in_array($name,['HOST','PORT','DATA_DIR','MOBILE_RELEASES_FILE','PATH','HOME','USER','SHELL'],true)||str_starts_with($name,'LD_')||str_starts_with($name,'DYLD_'))throw new InvalidArgumentException('Invalid or reserved deployment environment variable');
        }
        return ['port'=>$port,'variables'=>$variables];
    }
}
