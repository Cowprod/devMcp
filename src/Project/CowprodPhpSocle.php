<?php

declare(strict_types=1);

namespace Cowprod\DevMcp\Project;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;

/** Imports the approved Cowprod tree. The project owns everything outside the lock. */
final class CowprodPhpSocle
{
    private const DEPOT = 'Cowprod/referenciel';
    private const DOCUMENTS = ['README.md', 'NOMMAGE_SQL_PHP.md', 'FRONTEND.md', 'DEVELOPPEMENT_AGENTS_CI.md'];
    private const CONFIG = ['config/global.example.php', 'config/inc_connexion.example.php'];
    private const VERIFICATEUR = 'outils/verifier-socle.php';

    /** @return array<string, string> */
    public function lire(string $sSource, string $sRevision): array
    {
        if (!preg_match('/^[a-f0-9]{40}$/D', $sRevision) || realpath($sSource) !== $sSource)
            throw new InvalidArgumentException('Révision ou dossier du référentiel invalide');

        $sOrigine = trim($this->git($sSource, ['remote', 'get-url', 'origin']));
        if (!preg_match('~^(?:https://github\.com/|git@github\.com:|ssh://git@github\.com/)Cowprod/referenciel(?:\.git)?$~Di', $sOrigine))
            throw new InvalidArgumentException('Origine du référentiel non autorisée');
        $this->git($sSource, ['merge-base', '--is-ancestor', $sRevision, 'refs/remotes/origin/main']);
        $aSources = ['socle', ...self::CONFIG, ...self::DOCUMENTS, self::VERIFICATEUR];
        $sArbre = $this->git($sSource, ['ls-tree', '-r', '-z', $sRevision, '--', ...$aSources]);
        $aFichiers = [];
        foreach (explode("\0", rtrim($sArbre, "\0")) as $sEntree)
        {
            if (!preg_match('/^100(?:644|755) blob [a-f0-9]{40}\t([a-zA-Z0-9_.\/-]+)$/D', $sEntree, $aEntree)
                || preg_match('~(^|/)\.{1,2}(/|$)~', $aEntree[1]))
                throw new RuntimeException('Fichier non autorisé dans le socle');
            $sChemin = $aEntree[1];
            $sCible = match (true) {
                str_starts_with($sChemin, 'socle/') => 'public/'.substr($sChemin, 6),
                in_array($sChemin, self::DOCUMENTS, true) => 'docs/cowprod/'.$sChemin,
                default => $sChemin,
            };
            $aFichiers[$sCible] = $this->git($sSource, ['show', $sRevision.':'.$sChemin]);
        }
        if (array_diff(['public/index.php', self::VERIFICATEUR, ...self::CONFIG], array_keys($aFichiers)))
            throw new RuntimeException('Révision incomplète du socle Cowprod');

        $aFichiers['AGENTS.md'] = <<<'MD'
# Référentiel Cowprod

Lire et appliquer les documents dans `docs/cowprod/` avant toute modification SQL, PHP ou frontend.
Utiliser les API sous `public/api/` et les composants chargés par `top.php` et `bottom.php`.
Conserver les vrais fichiers de configuration dans `config/`, hors Git et hors du document root `public/`.
Initialiser la base selon les modules activés. Les exemples de configuration ne contiennent aucune clé utilisable.
Exécuter `php outils/verifier-socle.php` avant livraison. Vérifier aussi les conventions des modules métier.
La version et les empreintes du socle figurent dans `socle.lock.json`.
MD;
        $aFichiers['.gitignore'] = "/config/*\n!/config/global.example.php\n!/config/inc_connexion.example.php\n/vendor/\n";
        $aFichiers['.github/workflows/cowprod-php.yml'] = <<<'YAML'
name: Socle PHP Cowprod
on:
  push:
    branches: [main]
  pull_request:
concurrency:
  group: cowprod-php-${{ github.ref }}
  cancel-in-progress: true
jobs:
  conformity:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          coverage: none
      - run: php outils/verifier-socle.php
YAML;
        ksort($aFichiers);
        $aFichiers['socle.lock.json'] = json_encode([
            'repository' => self::DEPOT,
            'revision' => $sRevision,
            'files' => array_map(static fn(string $sTexte): string => hash('sha256', $sTexte), $aFichiers),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        return $aFichiers;
    }

    public function verifier(array $aFichiers): void
    {
        $sDossier = realpath(sys_get_temp_dir()).'/devmcp-socle-'.bin2hex(random_bytes(12));
        if (!mkdir($sDossier, 0700))
            throw new RuntimeException('Création du dossier de validation impossible');
        try
        {
            foreach ($aFichiers as $sChemin => $sTexte)
                $this->ecrire($sDossier.'/'.$sChemin, $sTexte);
            $oProcessus = new Process([PHP_BINARY, $sDossier.'/'.self::VERIFICATEUR, $sDossier]);
            $oProcessus->setTimeout(60);
            $oProcessus->mustRun();
        }
        finally
        {
            $aChemins = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($sDossier, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($aChemins as $oChemin)
            {
                if ($oChemin->isDir() && !$oChemin->isLink())
                    rmdir($oChemin->getPathname());
                else
                    unlink($oChemin->getPathname());
            }
            rmdir($sDossier);
        }
    }

    public function appliquer(string $sCible, array $aFichiers, bool $bMiseAJour = false): void
    {
        if (!is_dir($sCible) || realpath($sCible) !== $sCible)
            throw new InvalidArgumentException('Dossier cible invalide');
        $aAnciens = [];
        if ($bMiseAJour)
        {
            $sVerrou = $sCible.'/socle.lock.json';
            if (!is_file($sVerrou) || is_link($sVerrou))
                throw new RuntimeException('Projet sans verrou du socle');
            $aVerrou = json_decode(file_get_contents($sVerrou), true, 512, JSON_THROW_ON_ERROR);
            if (($aVerrou['repository'] ?? '') !== self::DEPOT || !is_array($aVerrou['files'] ?? null))
                throw new RuntimeException('Verrou du projet invalide');
            $aAnciens = $aVerrou['files'];
            if (array_diff_key($aAnciens, $aFichiers))
                throw new RuntimeException('Des fichiers sont retirés du socle. Revue manuelle requise.');
        }
        $aSauvegarde = [];
        foreach ($aFichiers as $sRelatif => $sTexte)
        {
            $sChemin = $sCible.'/'.$sRelatif;
            $sParent = dirname($sChemin);
            while (!file_exists($sParent) && !is_link($sParent))
                $sParent = dirname($sParent);
            if (realpath($sParent) !== $sParent || !is_dir($sParent) || is_link($sChemin))
                throw new RuntimeException('Chemin cible non autorisé : '.$sRelatif);
            if (isset($aAnciens[$sRelatif]) || ($bMiseAJour && $sRelatif === 'socle.lock.json'))
            {
                if (!is_file($sChemin) || (isset($aAnciens[$sRelatif]) && hash_file('sha256', $sChemin) !== $aAnciens[$sRelatif]))
                    throw new RuntimeException('Fichier du socle modifié localement : '.$sRelatif);
                $aSauvegarde[$sRelatif] = file_get_contents($sChemin);
            }
            elseif (file_exists($sChemin))
                throw new RuntimeException('Fichier cible déjà présent : '.$sRelatif);
        }
        $aEcrits = [];
        try
        {
            foreach ($aFichiers as $sRelatif => $sTexte)
            {
                $sChemin = $sCible.'/'.$sRelatif;
                $bExiste = array_key_exists($sRelatif, $aSauvegarde);
                if ($bExiste && $aSauvegarde[$sRelatif] === $sTexte)
                    continue;
                if ($bExiste && (is_link($sChemin) || file_get_contents($sChemin) !== $aSauvegarde[$sRelatif]))
                    throw new RuntimeException('Fichier cible modifié pendant la mise à jour : '.$sRelatif);
                $this->ecrire($sChemin, $sTexte, $bExiste);
                $aEcrits[] = $sRelatif;
            }
        }
        catch (\Throwable $oErreur)
        {
            foreach (array_reverse($aEcrits) as $sRelatif)
            {
                if (isset($aSauvegarde[$sRelatif]))
                    $this->ecrire($sCible.'/'.$sRelatif, $aSauvegarde[$sRelatif], true);
                elseif (is_file($sCible.'/'.$sRelatif))
                    unlink($sCible.'/'.$sRelatif);
            }
            throw $oErreur;
        }
    }

    private function ecrire(string $sChemin, string $sTexte, bool $bRemplacer = false): void
    {
        $sDossier = dirname($sChemin);
        if (!is_dir($sDossier) && !mkdir($sDossier, 0755, true))
            throw new RuntimeException('Création de dossier impossible');
        if (realpath($sDossier) !== $sDossier)
            throw new RuntimeException('Dossier cible non canonique');

        $sTemp = tempnam($sDossier, '.socle-');
        if ($sTemp === false)
            throw new RuntimeException('Préparation du fichier impossible');
        try
        {
            if (file_put_contents($sTemp, $sTexte) !== strlen($sTexte) || !chmod($sTemp, 0644))
                throw new RuntimeException('Écriture incomplète du socle');
            if (!($bRemplacer ? rename($sTemp, $sChemin) : @link($sTemp, $sChemin)))
                throw new RuntimeException('Publication refusée : '.$sChemin);
        }
        finally
        {
            if (is_file($sTemp))
                unlink($sTemp);
        }
    }

    private function git(string $sSource, array $aArguments): string
    {
        $oProcessus = new Process(['git', '-c', 'core.hooksPath=/dev/null', ...$aArguments], $sSource);
        $oProcessus->setTimeout(60);
        $oProcessus->mustRun();
        return $oProcessus->getOutput();
    }
}
