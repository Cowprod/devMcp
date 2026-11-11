<?php
declare(strict_types=1);
namespace Cowprod\DevMcp\Tests\Project;
use Cowprod\DevMcp\Project\MacIosRuntime;
use PHPUnit\Framework\TestCase;
use RuntimeException;
final class MacIosRuntimeTest extends TestCase
{
    public function testRequiresAnExplicitDestinationAndDoesNotFallBackFromDeviceToSimulator(): void
    {
        MacIosRuntime::arguments(['target'=>'simulator']);
        MacIosRuntime::arguments(['target'=>'device','team_id'=>'FB3A74YU84','method'=>'debugging']);
        self::expectException(RuntimeException::class);
        MacIosRuntime::arguments(['team_id'=>'FB3A74YU84']);
    }
    public static function invalidBuilds(): array
    {
        return [[['target'=>'simulator','method'=>'debugging']],[['target'=>'device','team_id'=>'bad']],
            [['target'=>'device','method'=>'app-store-connect']],[['target'=>'device','command'=>'whoami']]];
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidBuilds')]
    public function testRejectsInvalidBuildArguments(array $args): void
    {
        self::expectException(RuntimeException::class);MacIosRuntime::arguments($args);
    }
    public function testAcceptsAnExplicitWorkspaceAndScheme(): void
    {
        $config=MacIosRuntime::configuration(['kind'=>'workspace','path'=>'native/App.xcworkspace','scheme'=>'App','configuration'=>'Release']);
        self::assertSame('native/App.xcworkspace',$config['path']);self::assertNull($config['team_id']);
    }
    public function testRejectsContainerTraversal(): void
    {
        self::expectException(\InvalidArgumentException::class);
        MacIosRuntime::configuration(['kind'=>'project','path'=>'../Other.xcodeproj','scheme'=>'App','configuration'=>'Release']);
    }
}
