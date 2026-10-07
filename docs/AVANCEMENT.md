# Avancement

| Jalon | Statut | Branche / PR | Objet | Preuves |
| --- | --- | --- | --- | --- |
| J01 | ACCEPTÉ | main / PR #2 | cœur MCP sécurisé, registre projets, actions synchrones, audit, CI | merge 92640d353409e9e7a57f843358a0df3fb6969ead |
| J02 | PASS TECHNIQUE | feat/j02-jobs-artifacts | jobs asynchrones, lecture progressive des sorties, annulation, artefacts déclarés et bornés | GitHub Actions : SUCCESS ; 9 tests / 28 assertions |
| J03 | À FAIRE | - | Streamable HTTP sécurisé + authentification | - |
| J04 | À FAIRE | - | runners/agents distants et isolation par projet | - |
| J05 | À FAIRE | - | adapters Android/services/matériel selon priorité | - |

## Revue technique J02

- action_start retourne un job_id sans attendre la fin : PASS ;
- transition running → succeeded : PASS ;
- annulation d’un job actif : PASS ;
- lecture stdout/stderr par offsets bornés : PASS ;
- artefact déclaré : présence, taille, SHA-256 : PASS ;
- lecture base64 par chunks : PASS ;
- symlink d’artefact hors workspace : rejeté ;
- construction du serveur MCP avec les nouveaux outils : PASS ;
- régression J01 : PASS.

J02 ne dépend d’aucun choix de VM ou de réseau.
