<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use Cowprod\DevMcp\Distributed\Admin\HostingLabBatchConsent;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class HostingLabBatchConsentTest extends TestCase
{
    public function testLegacyAndByNamePhasesRequireSeparateApprovals(): void
    {
        $revision = str_repeat('a', 40);
        $id = str_repeat('b', 32);
        foreach ([
            ['--prepare', 'machine_id', $id, 'PREPARE_CASE_HOSTING_LAB', 'prepare'],
            ['--activate', 'machine_id', $id, 'ACTIVATE_CASE_HOSTING_LAB', 'activate'],
            ['--prepare-name', 'machine_name', 'case', 'PREPARE_CASE_HOSTING_LAB_BY_NAME', 'prepare'],
            ['--activate-name', 'machine_name', 'case', 'ACTIVATE_CASE_HOSTING_LAB_BY_NAME', 'activate'],
        ] as [$mode, $selector, $value, $approval, $phase]) {
            // Consent is a JSON object, independent of member order.
            $parsed = HostingLabBatchConsent::parse($mode, json_encode([
                'revision' => $revision, 'approval' => $approval, $selector => $value,
            ], JSON_THROW_ON_ERROR));
            self::assertSame($phase, $parsed['phase']);
            self::assertSame($revision, $parsed['revision']);
            self::assertSame($selector === 'machine_id' ? $id : null, $parsed['machine_id']);
            self::assertSame($selector === 'machine_name' ? 'case' : null, $parsed['machine_name']);
        }
    }

    public function testInvalidModeScopeApprovalAndIdentityFailClosed(): void
    {
        $revision = str_repeat('a', 40);
        $valid = ['machine_name' => 'case', 'revision' => $revision,
            'approval' => 'PREPARE_CASE_HOSTING_LAB_BY_NAME'];
        $cases = [
            ['--prepare-name', array_merge($valid, ['machine_name' => '../case'])],
            ['--prepare-name', array_merge($valid, ['machine_name' => 'Case\n'])],
            ['--prepare-name', array_merge($valid, ['machine_name' => str_repeat('x', 129)])],
            ['--prepare-name', array_merge($valid, ['machine_id' => str_repeat('b', 32)])],
            ['--prepare-name', array_merge($valid, ['approval' => 'ACTIVATE_CASE_HOSTING_LAB_BY_NAME'])],
            ['--prepare-name', array_merge($valid, ['revision' => str_repeat('0', 40)])],
            ['--prepare-name', array_merge($valid, ['revision' => strtoupper($revision)])],
            ['--activate-name', $valid],
            ['--prepare', $valid],
            ['--prepare-name', ['machine_id' => str_repeat('b', 32),
                'revision' => $revision, 'approval' => 'PREPARE_CASE_HOSTING_LAB']],
            ['--shell', $valid],
            ['--prepare-name', array_merge($valid, ['command' => 'sh'])],
        ];
        foreach ($cases as [$mode, $data]) {
            try {
                HostingLabBatchConsent::parse(
                    $mode, json_encode($data, JSON_THROW_ON_ERROR)
                );
                self::fail('Unapproved central batch consent accepted for ' . $mode);
            } catch (InvalidArgumentException $expected) {
                self::assertNotSame('', $expected->getMessage());
            }
        }
        foreach (['', '[]', '{}', 'null', '{"invalid":true}', str_repeat(' ', 513)] as $raw) {
            try {
                HostingLabBatchConsent::parse('--prepare-name', $raw);
                self::fail('Malformed central batch consent accepted');
            } catch (InvalidArgumentException $expected) {
                self::assertNotSame('', $expected->getMessage());
            }
        }
    }
}
