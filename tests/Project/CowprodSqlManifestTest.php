<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Project;

use PHPUnit\Framework\TestCase;

final class CowprodSqlManifestTest extends TestCase
{
    public function testNormalizedMigrationsMatchTheSchemaManifest(): void
    {
        $root = dirname(__DIR__,2);
        $manifest = json_decode(file_get_contents($root.'/docs/cowprod-sql-rename-map.json'),true,flags:JSON_THROW_ON_ERROR);
        $targets = array_column($manifest,null,'target');
        $checked = 0;
        foreach (glob($root.'/migrations/*cowprod*.sql') as $file) {
            preg_match_all('/CREATE TABLE IF NOT EXISTS (T_[A-Z0-9]+)\s*\(\h*\R(.*?)\R\) ENGINE/s',file_get_contents($file),$tables,PREG_SET_ORDER);
            foreach ($tables as [,$name,$definition]) {
                self::assertArrayHasKey($name,$targets,basename($file));
                self::assertArrayHasKey('target_columns',$targets[$name],$name);
                preg_match_all('/^\h+([A-Z0-9]{3}_[A-Z0-9_]+)\h+/m',$definition,$columns);
                self::assertEqualsCanonicalizing($targets[$name]['target_columns'],$columns[1],$name);
                ++$checked;
            }
        }
        self::assertGreaterThan(0,$checked);
    }
}
