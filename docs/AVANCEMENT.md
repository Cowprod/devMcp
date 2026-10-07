# Avancement

| Jalon | Statut | Branche / PR | Objet | Preuves |
| --- | --- | --- | --- | --- |
| J01 | ACCEPTÉ | main / PR #2 | cœur MCP sécurisé, registre projets, actions synchrones, audit, CI | merge 92640d353409e9e7a57f843358a0df3fb6969ead |
| J02 | ACCEPTÉ | main / PR #3 | jobs asynchrones, sorties progressives, annulation, artefacts bornés | merge 37dab1bb3cee0d4a6cd1edce0b3fb7778da77231 |
| J03 | ACCEPTÉ TECHNIQUEMENT | main / PR #4 | jobs persistants, worker séparé, HTTP local, Secure MCP Tunnel | merge 8ffec073370352846548fcb0ef2da4353f3dcd60 ; intégration VM/tunnel physique à faire |
| J04 | PASS TECHNIQUE | feat/j04-targeted-runners | targets de workers, sérialisation par workspace, sync explicite | CI à confirmer sur head final |
| J05 | À FAIRE | - | première intégration projet réelle, priorité multicam/Android | - |

## Revue technique J04

- target déclaré par action : PASS ;
- target persisté dans le job : PASS ;
- worker filtré par DEVMCP_TARGET : PASS ;
- worker local incapable de réclamer un job android-lab : PASS ;
- incohérence target job/manifest rejetée : PASS ;
- verrou exclusif par projet pendant l'exécution : PASS ;
- action_run interdit par défaut : PASS ;
- action_run exige sync=true et target=local : PASS ;
- action_start reste le chemin standard pour build/test/mutation : PASS.

## Limite volontaire

J04 introduit le modèle de targets et l'isolation logique des workers. Le store de jobs reste local à une VM. Un runner sur une autre machine nécessitera un transport de runner dédié ou une instance devMcp distincte reliée par son propre tunnel. Aucun partage NFS de la file n'est imposé par ce jalon.
