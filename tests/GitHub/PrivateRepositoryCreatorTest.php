<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\GitHub;

use Cowprod\DevMcp\GitHub\PrivateRepositoryCreator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PrivateRepositoryCreatorTest extends TestCase
{
    private string $directory;
    private string $tokenFile;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/devmcp-github-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->tokenFile = $this->directory . '/token';
        file_put_contents($this->tokenFile, 'github_pat_' . str_repeat('x', 40) . "\n");
        chmod($this->tokenFile, 0600);
    }

    protected function tearDown(): void
    {
        @unlink($this->tokenFile);
        @rmdir($this->directory);
    }

    public function testDisabledByDefaultAndRejectsUnexpectedSecretPath(): void
    {
        self::assertNull(PrivateRepositoryCreator::fromConfig([]));
        self::assertNull(PrivateRepositoryCreator::fromConfig(['github_repository_creation' => ['enabled' => false]]));
        $this->expectException(InvalidArgumentException::class);
        PrivateRepositoryCreator::fromConfig(['github_repository_creation' => [
            'enabled' => true, 'organization' => 'Cowprod', 'token_file' => $this->tokenFile,
        ]]);
    }

    public function testSendsOnlyBoundedPrivateRepositoryCreation(): void
    {
        $callCount = 0;
        $transport = static function (string $url, string $token, array $body) use (&$callCount): array {
            $callCount++;
            self::assertSame('https://api.github.com/orgs/Cowprod/repos', $url);
            self::assertStringStartsWith('github_pat_', $token);
            self::assertSame([
                'name' => 'demo-new',
                'private' => true,
                'visibility' => 'private',
                'auto_init' => true,
            ], $body);
            return ['status' => 201, 'body' => json_encode([
                'id' => 42, 'private' => true, 'name' => 'demo-new',
                'owner' => ['login' => 'Cowprod'],
            ], JSON_THROW_ON_ERROR)];
        };
        $creator = new PrivateRepositoryCreator('Cowprod', $this->tokenFile, $transport);
        $result = $creator->create('Cowprod/demo-new', 'CREATE_PRIVATE_REPOSITORY');
        self::assertSame(1, $callCount);
        self::assertSame('github_created', $result['state']);
        self::assertSame('https://github.com/Cowprod/demo-new', $result['url']);
        self::assertTrue($result['private']);
        self::assertFalse($result['project_registered']);
        self::assertFalse($result['workspace_created']);
        self::assertFalse($result['actions_authorized']);
    }

    public function testValidationPrecedesNetwork(): void
    {
        $transport = static function (): array {
            self::fail('HTTP transport must not run');
        };
        $creator = new PrivateRepositoryCreator('Cowprod', $this->tokenFile, $transport);
        foreach ([
            ['Cowprod/demo-new', 'yes'],
            ['Other/demo-new', 'CREATE_PRIVATE_REPOSITORY'],
            ['Cowprod/../bad', 'CREATE_PRIVATE_REPOSITORY'],
            ['Cowprod/-bad', 'CREATE_PRIVATE_REPOSITORY'],
        ] as [$repository, $confirmation]) {
            try {
                $creator->create($repository, $confirmation);
                self::fail('Invalid create accepted');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testTokenPermissionsAreEnforcedBeforeNetwork(): void
    {
        chmod($this->tokenFile, 0644);
        $creator = new PrivateRepositoryCreator('Cowprod', $this->tokenFile, static function (): array {
            self::fail('Untrusted token file must not be used');
        });
        $this->expectException(RuntimeException::class);
        $creator->create('Cowprod/demo-new', 'CREATE_PRIVATE_REPOSITORY');
    }

    public function testGithubErrorOrPublicResponseNeverReportedAsSuccess(): void
    {
        foreach ([
            ['status' => 422, 'body' => '{"message":"already exists"}'],
            ['status' => 201, 'body' => '{"id":42,"private":false,"name":"demo-new","owner":{"login":"Cowprod"}}'],
        ] as $reply) {
            $creator = new PrivateRepositoryCreator('Cowprod', $this->tokenFile, static fn(): array => $reply);
            try {
                $creator->create('Cowprod/demo-new', 'CREATE_PRIVATE_REPOSITORY');
                self::fail('Unexpected success');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }
}
