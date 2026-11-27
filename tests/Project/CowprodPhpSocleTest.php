<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Tests\Project;

use Cowprod\DevMcp\Project\CowprodPhpSocle;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

final class CowprodPhpSocleTest extends TestCase
{
    public function testImportsExactApprovedRevisionAndRefusesToOverwriteManagedChanges(): void
    {
        $sRacine = realpath(sys_get_temp_dir()).'/cowprod-'.bin2hex(random_bytes(8));
        $sSource = $sRacine.'/source';
        $sProjet = $sRacine.'/projet';
        mkdir($sSource, 0700, true);
        mkdir($sProjet);
        try {
            foreach (['socle', 'config', 'outils'] as $sDossier)
                mkdir($sSource.'/'.$sDossier);
            file_put_contents($sSource.'/socle/index.php', '<?php echo "socle";');
            file_put_contents($sSource.'/config/global.example.php', '<?php');
            file_put_contents($sSource.'/config/inc_connexion.example.php', '<?php');
            file_put_contents($sSource.'/outils/verifier-socle.php', '<?php exit(!is_file($argv[1]."/socle.lock.json"));');
            $this->git($sSource, ['init', '-b', 'main']);
            $this->git($sSource, ['add', '.']);
            $this->git($sSource, ['-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-m', 'Socle']);
            $sRevision = $this->git($sSource, ['rev-parse', 'HEAD']);
            $this->git($sSource, ['remote', 'add', 'origin', 'https://github.com/Cowprod/referenciel.git']);
            $this->git($sSource, ['update-ref', 'refs/remotes/origin/main', $sRevision]);

            $oSocle = new CowprodPhpSocle();
            $aFichiers = $oSocle->lire($sSource, $sRevision);
            $oSocle->verifier($aFichiers);
            $oSocle->appliquer($sProjet, $aFichiers);
            self::assertSame($sRevision, json_decode(file_get_contents($sProjet.'/socle.lock.json'), true)['revision']);
            self::assertStringContainsString('outils/verifier-socle.php', $aFichiers['.github/workflows/cowprod-php.yml']);

            file_put_contents($sProjet.'/public/module.php', 'Module métier');
            $oSocle->appliquer($sProjet, $aFichiers, true);
            self::assertSame('Module métier', file_get_contents($sProjet.'/public/module.php'));
            file_put_contents($sProjet.'/public/index.php', 'Modification locale');
            try {
                $oSocle->appliquer($sProjet, $aFichiers, true);
                self::fail('Une divergence locale doit bloquer la mise à jour');
            } catch (RuntimeException $oErreur) {
                self::assertStringContainsString('public/index.php', $oErreur->getMessage());
                self::assertSame('Modification locale', file_get_contents($sProjet.'/public/index.php'));
            }
        } finally {
            $aChemins = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sRacine, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($aChemins as $oChemin) {
                if ($oChemin->isDir() && !$oChemin->isLink())
                    rmdir($oChemin->getPathname());
                else
                    unlink($oChemin->getPathname());
            }
            rmdir($sRacine);
        }
    }

    private function git(string $sDossier, array $aArguments): string
    {
        return trim((new Process(['git', ...$aArguments], $sDossier))->mustRun()->getOutput());
    }
}
