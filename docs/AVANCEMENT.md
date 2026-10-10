# Avancement

| Jalon | Statut | Branche / PR | Objet | Preuves |
| --- | --- | --- | --- | --- |
| J01 | ACCEPTÉ | main / PR #2 | cœur MCP sécurisé, registre projets, actions synchrones, audit, CI | merge 92640d353409e9e7a57f843358a0df3fb6969ead |
| J02 | ACCEPTÉ | main / PR #3 | jobs asynchrones, sorties progressives, annulation, artefacts bornés | merge 37dab1bb3cee0d4a6cd1edce0b3fb7778da77231 |
| J03 | ACCEPTÉ | main / PR #4 + qualification VM | jobs persistants, worker séparé, Secure MCP Tunnel | tunnel réel opérationnel, service systemd, server/discover 2026-07-28 validé |
| J04 | ACCEPTÉ | main | targets de workers, sérialisation par workspace, sync explicite | worker local + workspace.sync qualifiés sur VM touchdeck |
| J05 | ACCEPTÉ | PR #8/#9 | première intégration projet réelle : touchDeck | project_list, git.status, git.head, workspace.sync depuis ChatGPT |
| J06 | ACCEPTÉ | PR #10/#11 | compatibilité tunnel dual-era + déploiement worker/tunnel | CI + qualification réelle |
| J07 | ACCEPTÉ | PR #12/#13/#14 | capacités hôte globales et diagnostic série | /dev/ttyACM0 détecté, droits runtime exposés |
| J08 | ACCEPTÉ | PR #15 | bootstrap ESP32 reproductible | PlatformIO 6.2.0 épinglé, dialout, PATH/HOME systemd |
| J09 | ACCEPTÉ | PR #16 | presets d’actions Git/PlatformIO versionnés | serial_device borné, build/upload sans shell libre |
| J10 | ACCEPTÉ | PR #17 | application runtime VM en une commande | deploy/apply-vm-runtime.sh, CI verte |
| J11 | EN COURS | - | qualification toolchain ESP32 sur VM réelle | déploiement et relecture host_capabilities à faire |

## État de la chaîne validée

ChatGPT
→ Secure MCP Tunnel
→ devMcp STDIO
→ registre projet
→ action synchrone ou job persistant
→ worker local
→ workspace Git borné
→ audit et sorties persistantes.

Le test réel workspace.sync sur touchDeck a terminé succeeded avec code 0 et traces job_queued, job_started, job_finished.

## Principes conservés

- aucun shell arbitraire ;
- exécutables absolus et paramètres typés ;
- actions génériques versionnées et activées explicitement par projet ;
- secrets et configuration locale hors Git ;
- worker unique par target, pas un worker par projet ;
- accès série via dialout, sans sudo accordé à devMcp ;
- GitHub reste le plan de contrôle du code, devMcp le plan d’exécution local.
