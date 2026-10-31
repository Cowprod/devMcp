<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Distributed;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/bin/remote-job-policy.php';

/**
 * The web UI can describe a remote action without granting permission for it.
 * The queue allowlist and the independent agent policy remain authoritative.
 */
final class AdminRemoteActionCatalogueTest extends TestCase
{
    public function testRemoteCatalogueIsOnlyTheFiveReadOnlyActions(): void
    {
        $catalogue = \remoteReadonlyActionCatalog();
        self::assertSame([
            'git.head',
            'workspace.list',
            'workspace.read',
            'workspace.stat',
            'workspace.find',
        ], array_keys($catalogue));

        foreach ($catalogue as $action => $spec) {
            self::assertIsString($spec['description']);
            self::assertNotSame('', $spec['description']);
            self::assertIsBool($spec['needs_path']);
            self::assertIsBool($spec['supports_filter']);
            self::assertFalse($spec['supports_filter'] && !$spec['needs_path']);
            if ($spec['supports_filter']) {
                self::assertSame(['all', 'php', 'js', 'json', 'md'], $spec['filter_values']);
            } else {
                self::assertArrayNotHasKey('filter_values', $spec);
            }
        }

        self::assertTrue(\remoteJobAllowed('git.head', []));
        self::assertFalse(\remoteJobAllowed('git.head', ['path' => '.']));
        self::assertTrue(\remoteJobAllowed('workspace.read', ['path' => 'README.md']));
        self::assertFalse(\remoteJobAllowed('workspace.read', ['path' => '../private']));
        self::assertTrue(\remoteJobAllowed('workspace.find', ['path' => '.', 'filter' => 'md']));
        self::assertFalse(\remoteJobAllowed('workspace.find', ['path' => '.', 'filter' => 'sh']));
        self::assertFalse(\remoteJobAllowed('workspace.write_text', ['path' => 'README.md']));
    }


    public function testProjectActionsShowOnlyBoundedLastKnownJobStatus(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/distributed-admin.php');
        $dashboardSource = file_get_contents(
            dirname(__DIR__, 2) . '/src/Distributed/Admin/DashboardRepository.php'
        );
        self::assertIsString($source);
        self::assertIsString($dashboardSource);

        // The same server-projected, bounded metadata drives the action and
        // deployment views. No job argument or result is fetched by this UI.
        self::assertStringContainsString('function renderRemoteCatalogueStatuses(', $source);
        self::assertStringContainsString(
            'remoteJobHistory.find(job=>job.deployment_id===deploymentId && job.action_name===entry.dataset.remoteAction)',
            $source,
        );
        self::assertStringContainsString(
            'renderRemoteCatalogueStatuses(catalogue,deployment.dataset.recordId);',
            $source,
        );
        self::assertStringContainsString(
            "document.querySelectorAll('#panel-projects .registry-remote-catalogue').forEach(catalogue=>{",
            $source,
        );
        self::assertStringContainsString('Aucune demande parmi les 100 dernières', $source);
        self::assertStringContainsString('Historique indisponible', $source);
        self::assertStringContainsString('ne prouve aucune autorisation actuelle', $source);
        self::assertStringContainsString('ORDER BY dm_remote_readonly_job.created_at DESC', $dashboardSource);
        self::assertStringContainsString('LIMIT ', $dashboardSource);
        self::assertStringNotContainsString('dm_remote_readonly_job.arguments_json', $dashboardSource);
        self::assertStringNotContainsString('dm_remote_readonly_job.result_json', $dashboardSource);
    }

    public function testProjectActionFilterUsesTypedLocalInventoryAndDoesNotChangeAuthorization(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/distributed-admin.php');
        self::assertIsString($source);

        // Local action IDs come from the validated snapshot, not from a
        // comma-separated display string (which is not a data protocol).
        self::assertStringContainsString('data-local-actions=', $source);
        self::assertStringContainsString("JSON.parse(row.dataset.localActions||'[]')", $source);
        self::assertStringNotContainsString("split(',').map(v=>v.trim())", $source);

        // The search only toggles catalogue visibility; it must not alter
        // the five-action queue allowlist, form content or agent policy.
        self::assertStringContainsString('function bindProjectActionFilter(', $source);
        self::assertStringContainsString('entry.dataset.actionSearch=', $source);
        self::assertStringContainsString("entry.classList.toggle('d-none',!matched);", $source);
        self::assertStringContainsString("catalogue.classList.toggle('d-none',origin==='local'||(!!query&&!hasMatch));", $source);
        self::assertStringContainsString('bindProjectActionFilter(filterInput,list,counter,empty);', $source);
        self::assertStringContainsString("hidden('operation','remote.readonly.enqueue');", $source);
        self::assertStringContainsString('Conditionnel : politique agent', $source);
    }

    public function testProjectCataloguePreparesExistingReadOnlyFormWithoutQueueing(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/distributed-admin.php');
        self::assertIsString($source);

        // A click only selects one of the server-published read-only actions.
        // Buttons are created only after eligibility and CSRF checks.
        $eligibilityGate = strpos($source, 'if(!enabled||!approved){');
        $csrfGate = strpos($source, 'if(!csrf){');
        $prepareControl = strpos($source, "prepare.dataset.prepareRemoteAction=action;");
        self::assertIsInt($eligibilityGate);
        self::assertIsInt($csrfGate);
        self::assertIsInt($prepareControl);
        self::assertLessThan($prepareControl, $eligibilityGate);
        self::assertLessThan($prepareControl, $csrfGate);
        self::assertStringContainsString(
            "if(!Object.prototype.hasOwnProperty.call(remoteActionCatalog,action))return;", $source,
        );
        self::assertStringContainsString("prepare.type='button';", $source);
        self::assertStringContainsString(
            "actions.dispatchEvent(new Event('change',{bubbles:true}));", $source,
        );
        self::assertStringContainsString("preparedNote.setAttribute('role','status');", $source);
        self::assertStringContainsString("entry.prepend(prepare);", $source);
        self::assertStringContainsString("hidden('operation','remote.readonly.enqueue');", $source);
        self::assertStringContainsString("submit.type='submit';", $source);

        // Neither new action IDs nor a direct execution endpoint are introduced.
        self::assertStringNotContainsString("form.submit()", $source);
        self::assertSame(5, count(\remoteReadonlyActionCatalog()));
    }

    public function testProjectActionOriginFilterOnlyChangesVisibleCatalogueEntries(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/distributed-admin.php');
        self::assertIsString($source);
        self::assertStringContainsString("line.dataset.actionOrigin='local';", $source);
        self::assertStringContainsString("entry.dataset.actionOrigin='remote';", $source);
        self::assertStringContainsString("['all','Toutes'],['local','Locales'],['remote','Distantes']", $source);
        self::assertStringContainsString('function bindProjectActionFilter(input,originSelect,list,counter,empty)', $source);
        self::assertStringContainsString("originSelect.addEventListener('change',update);", $source);
        self::assertStringContainsString("origin==='all'||entry.dataset.actionOrigin===origin", $source);
        self::assertStringContainsString("catalogue.classList.toggle('d-none',origin==='local'||(!!query&&!hasMatch));", $source);
        self::assertStringContainsString("bindProjectActionFilter(filterInput,originSelect,list,counter,empty);", $source);
        // Only the catalogue entries are hidden; no submitted field, queue or permission changes.
        self::assertStringContainsString("entry.classList.toggle('d-none',!matched);", $source);
        self::assertStringContainsString("hidden('operation','remote.readonly.enqueue');", $source);
        self::assertSame(5, count(\remoteReadonlyActionCatalog()));
    }

    public function testAdminPageSharesTheCatalogueForFallbackAndProjectDetails(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/public/distributed-admin.php');
        self::assertIsString($source);
        // No independent hard-coded list that could diverge from the enqueue gate.
        self::assertStringContainsString(
            'foreach (array_keys(remoteReadonlyActionCatalog()) as $action)',
            $source,
        );
        self::assertStringContainsString(
            'const remoteActionCatalog = <?= json_encode(remoteReadonlyActionCatalog()',
            $source,
        );
        self::assertStringContainsString(
            'Object.entries(remoteActionCatalog).forEach(([action,spec])=>',
            $source,
        );
        self::assertStringContainsString('Catalogue distant (lecture seule)', $source);
        self::assertStringContainsString('Conditionnel : politique agent', $source);
        self::assertStringContainsString(
            "remoteReadonlyActionCatalog()['workspace.find']['filter_values'] as \$filter", $source,
        );
        self::assertStringContainsString(
            "remoteActionCatalog['workspace.find'].filter_values.forEach(name=>", $source,
        );
        self::assertStringContainsString('function bindRemoteFields(', $source);
        self::assertStringContainsString('bindRemoteFields(actions,path,filter,help);', $source);
        self::assertStringContainsString('bindRemoteFields(actions,pathGroup,filterGroup,actionHelp);', $source);
        self::assertStringContainsString("if(!enabled||!approved){", $source);
    }
}
