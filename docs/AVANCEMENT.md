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
| J12 | PARTIEL | feat/node-pm2-workspace | inspection find, actions Node/npm, PM2 et documentation | CI et qualification VM à confirmer ; premier npm install non reproductible sans lockfile TouchDeck |
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

## Diagnostics Android ADB — 17 novembre 2026

Implémentation commune des actions `android.device_info`, `android.storage`, `android.files`, `android.storage_report`, `android.logcat`, avec la politique d’appareils de l’action d’installation existante. Profil MultiCam déclaré dans le registre central uniquement ; aucun changement dans son dépôt. Migration transactionnelle et idempotente, commandes en lecture seule, sorties/délais bornés, accès privé exclusivement par `run-as`. Voir [ANDROID-DIAGNOSTICS.md](ANDROID-DIAGNOSTICS.md).

Validation locale : tests de stockage sans double comptage, autorisation, refus de paramètres, droits privés, absence de résultats inventés, candidats doublons, bornes et transport MCP. Qualification physique du helper réalisée sur les cinq tablettes : quatre Xiaomi `24075RP89G` et une Samsung `SM-X110`, API 36. Découverte, système, stockage, quatre emplacements et quatre filtres logcat exercés en lecture seule. `DCIM/MultiCam` est absent sur les cinq appareils ; `run-as` MultiCam est autorisé sur les cinq. MultiCam est arrêté sur Samsung : les filtres applicatifs renvoient `app_not_running`, sans lancer l’application. Les preuves de CI, de migration SQL et d’appels via le serveur MCP actif sont consignées dans le compte rendu de livraison/PR ; une qualification du helper seule ne suffit pas à les établir.

## Admission Android USB / Wi-Fi — 19 novembre 2026

Suppression de l’admission par listes de séries déclarées dans `android.install`. Critère commun : identifiant présent dans l’inventaire ADB courant en état `device`. Normalisation des anciens sélecteurs enum pour toutes les actions Android, contrôle au lancement synchrone et à l’exécution des jobs, diagnostics indépendants de l’action d’installation. Les états hors ligne/non autorisés restent refusés. Documentation : [ANDROID-DIAGNOSTICS.md](ANDROID-DIAGNOSTICS.md). La qualification de la Samsung SM-X110 doit utiliser son identifiant Wi-Fi courant, sans le remplacer par sa série USB.
