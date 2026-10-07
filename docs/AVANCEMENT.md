# Avancement

| Jalon | Statut | Branche / PR | Objet | Preuves |
| --- | --- | --- | --- | --- |
| J01 | ACCEPTÉ | main / PR #2 | cœur MCP sécurisé, registre projets, actions synchrones, audit, CI | merge 92640d353409e9e7a57f843358a0df3fb6969ead |
| J02 | ACCEPTÉ | main / PR #3 | jobs asynchrones, sorties progressives, annulation, artefacts bornés | merge 37dab1bb3cee0d4a6cd1edce0b3fb7778da77231 |
| J03 | PASS TECHNIQUE | feat/j03-http-tunnel | jobs persistants, worker séparé, Streamable HTTP local, Secure MCP Tunnel, templates VM | CI SUCCESS ; 11 tests / 35 assertions ; lint entrypoints |
| J04 | À FAIRE | - | runners/targets et isolation multi-machine | - |
| J05 | À FAIRE | - | première intégration projet réelle, priorité multicam/Android | - |

## Revue technique J03

- jobs persistants entre instances de JobManager : PASS ;
- claim de job atomique : PASS ;
- transitions concurrentes protégées par verrou par job : PASS ;
- worker séparé : PASS ;
- timeout worker : PASS ;
- annulation d’un job queued : PASS ;
- sorties persistantes bornées : PASS ;
- artefacts après exécution persistante : PASS ;
- symlink d’artefact hors workspace : rejeté ;
- Streamable HTTP initialize : PASS ;
- FileSessionStore HTTP : PASS ;
- STDIO conservé : PASS ;
- endpoint HTTP template limité à 127.0.0.1 : PASS ;
- diagnostic VM ajouté : PASS ;
- chemin ChatGPT recommandé documenté : Secure MCP Tunnel + STDIO ;
- aucune API key propriétaire ajoutée au protocole MCP.

La seule preuve manquante pour ACCEPTÉ est l’intégration physique sur la VM : tunnel-client + service worker + premier appel depuis ChatGPT. Le code et l’architecture peuvent être mergés indépendamment de cette qualification physique.
