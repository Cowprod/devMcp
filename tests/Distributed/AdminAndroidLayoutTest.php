<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use PHPUnit\Framework\TestCase;

/**
 * The Android catalogue is a presentation of existing ADB controls only.
 * It must not duplicate forms or introduce executable agent permissions.
 */
final class AdminAndroidLayoutTest extends TestCase
{
    private function adminSource(): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/distributed-admin.php');
        self::assertIsString($source);

        return $source;
    }

    public function testAndroidUsesTheSharedMasterDetailLayout(): void
    {
        $source = $this->adminSource();
        self::assertStringContainsString("const panel = document.getElementById('panel-android');", $source);
        self::assertStringContainsString("const layout = create('div', 'registry-layout');", $source);
        self::assertStringContainsString("const sidebar = create('aside', 'registry-sidebar');", $source);
        self::assertStringContainsString("const detail = create('div', 'registry-detail');", $source);
        self::assertStringContainsString("labelForm.className = 'input-group mb-3';", $source);
        self::assertStringContainsString("item.card.hidden = !active;", $source);
        self::assertStringContainsString(".android-device-detail[hidden]{display:none!important}", $source);
    }

    public function testAndroidReusesExistingCsrfProtectedFormsAndLiveCapture(): void
    {
        $source = $this->adminSource();
        foreach (['android.label.save', 'android.screenshot.request', 'android.log.request'] as $operation) {
            self::assertStringContainsString('value="' . $operation . '"', $source);
        }
        self::assertStringContainsString("body.appendChild(labelForm);", $source);
        self::assertStringContainsString("buttons.appendChild(form)", $source);
        self::assertStringContainsString("capture.append(...Array.from(originalCapture.childNodes));", $source);
        self::assertStringContainsString("originalCapture.classList.remove('adb-capture-cell');", $source);
        self::assertStringContainsString("const pending = Array.from(document.querySelectorAll('.adb-capture-cell[data-pending=\"1\"]'));", $source);
        self::assertStringContainsString("original.classList.add('d-none');", $source);
        self::assertStringContainsString("sessionStorage.setItem('devmcp-android-selection'", $source);
    }

    public function testProjectRegistrationDoesNotMisrepresentGithubProvisioning(): void
    {
        $source = $this->adminSource();
        self::assertStringContainsString('Enregistrer la fiche', $source);
        self::assertStringContainsString('Elle ne crée ni dépôt GitHub, ni workspace, ni autorisation', $source);
        self::assertStringContainsString("$editor->createProject(", $source);
    }
}
