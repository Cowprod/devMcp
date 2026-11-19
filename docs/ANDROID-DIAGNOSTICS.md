# Diagnostics Android ADB en lecture seule

Les diagnostics utilisent les actions partagées, le registre SQL et le client ADB existants de TouchDeck. Ils ne créent pas un deuxième inventaire ou une nouvelle liste d’appareils. La seule condition d’admission d’un appareil est sa présence actuelle dans `adb devices` avec l’état exact `device`. Aucun numéro USB déclaré dans `android.install` n’est utilisé comme liste d’autorisation. USB, adresses Wi-Fi et identifiants mDNS/TLS sont traités de la même manière. Les états `offline`, `unauthorized`, `recovery` et `sideload` sont refusés.

## Actions MCP

Utiliser `action_describe`, puis `action_run` (ou `action_start` + `job_status`) avec `project: "multicam"` et l’action ci-dessous. Le résultat standard contient un document JSON dans `stdout`. `ok` décrit l’exécution du diagnostic ; les statuts de chaque emplacement/commande figurent dans ce document.

| Action | Arguments obligatoires | Contenu |
|---|---|---|
| `android.device_info` | `device` | Fabricant, modèle, Android/API, série, connexion, stockage |
| `android.storage` | `device` | Volumes Android, `df`, `stat -f`, octets et unités IEC |
| `android.files` | `device`, `location`, `limit` (1–100) | Métadonnées, huit niveaux au maximum, sans suivre les liens |
| `android.storage_report` | `device` | Fichiers MP4 observés, tailles, public/privé, candidats doublons et temporaires |
| `android.logcat` | `device`, `filter`, `lines` (1–500) | Dump fini des journaux récents ; aucun effacement |

Exemple :

```json
{"project":"multicam","action":"android.files","arguments":{"device":"61cc29567d91","location":"app_private","limit":50}}
```

Les arguments inconnus, les valeurs hors limites et les filtres/chemins libres sont refusés. Les paramètres invalides des nouvelles actions renvoient une erreur MCP `INVALID_ANDROID_DIAGNOSTIC_ARGUMENTS`, sans lancer de commande.

## Emplacements et profils

- `public_dcim` : `/sdcard/DCIM/<public_directory>` ; pour MultiCam, `MultiCam`.
- `public_downloads`, `public_pictures`, `public_music`, `public_documents`, `public_dcim_all` : respectivement `/sdcard/Download`, `/sdcard/Pictures`, `/sdcard/Music`, `/sdcard/Documents`, `/sdcard/DCIM`. Parcours borné, lecture seule, aucun accès aux données privées des autres applications.
- `public_movies` : `/sdcard/Movies`. L’origine applicative de ces vidéos est inconnue et n’est jamais attribuée automatiquement à MultiCam.
- `app_external` : `/sdcard/Android/data/<package>/files`.
- `app_private` : répertoire privé du package, exclusivement par `run-as` si Android l’autorise pour l’application débogable. Aucun root, `su`, changement de permissions ou contournement.

Le profil central de MultiCam reprend le package déjà utilisé par le pont d’administration : `fr.emmanuel.multicam`. Il est conservé dans `dm_local_project_config.config_json.android_diagnostics`, sans modifier son dépôt. Pour un autre projet, le même objet peut déclarer `package` et `public_directory`. L’accès aux diagnostics ne dépend pas de la présence ni des paramètres de `android.install`. Les droits d’exécuter une action sur un projet restent distincts : aucune action d’installation n’est ajoutée à un projet qui ne la déclarait pas. Sans profil, les lectures système et `public_movies` restent disponibles, les emplacements applicatifs renvoient `location_unconfigured`.

La migration `bin/migrate-android-diagnostics.php --execute MIGRATE_ANDROID_DIAGNOSTICS` publie uniquement les cinq nouveaux templates et les active sur les projets locaux disposant déjà de la capacité d’installation Android. Elle conserve les définitions d’actions ; leurs anciennes listes de séries ne font plus autorité. Elle est transactionnelle et idempotente, sans nouveau schéma SQL. L’installation du runtime local appelle cette migration lors des mises à jour normales.

## Stockage et limites des mesures

`df -k` donne le périphérique et le point de montage ; `stat -f` donne les blocs et l’espace disponible pour l’utilisateur. Les valeurs sont exprimées en octets et unités IEC (KiB, MiB, GiB). Les blocs réservés sont distingués de l’espace disponible.

Le stockage émulé partagé et son volume privé sous-jacent sont regroupés par les identifiants d’Android StorageManager lorsqu’ils sont déterminés. Des capacités identiques seules ne prouvent jamais une identité. Les relations inconnues empêchent une agrégation globale ; les observations par montage restent disponibles. Une carte publique montée est observée séparément.

Les rapports limitent le parcours à 500 entrées par emplacement et huit niveaux. `complete`, `truncated`, `depth_limited` et les statuts d’accès indiquent les limites. Les totaux globaux ou par portée incomplète sont `null` ; `observed_*` indique seulement le sous-ensemble effectivement observé. Les dossiers absents sont signalés `not_found`, distinctement d’un dossier vide ou inaccessible. Les tailles sont logiques, pas des blocs alloués ; une application active peut modifier ses fichiers pendant la photographie.

Les noms MP4 ne constituent pas une détection par contenu. Les candidats doublons ont même nom et même taille mais `proof_of_duplicate: false`. Les temporaires sont des candidats par suffixe et ancienneté supérieure à 24 h ; ils peuvent encore être utilisés. Rien n’est effacé.

## Journaux

Filtres autorisés : `multicam`, `app`, `camera`, `storage`, `transfer`. `multicam` exige le package MultiCam ; `app` utilise le profil du projet. Ces deux filtres et `transfer` sont limités au processus courant de l’application. `camera` et `storage` utilisent des tags système fixes. `transfer` sélectionne les messages du processus évoquant un transfert/synchronisation ; il ne reconstitue pas l’historique d’un processus terminé.

Les commandes utilisent `logcat -d -t`, jamais `-c`/`--clear` ni surveillance continue. Une application inactive renvoie `app_not_running`. Les dumps longs conservent une fin bornée et signalent la troncature. Les journaux peuvent contenir des données applicatives : ils restent réservés aux utilisateurs administrateurs MCP autorisés.

## Protections et erreurs

Aucun paramètre ne contient de commande, chemin, package ou tag libre. Le programme distant de parcours est fixe, ses arguments sont échappés et il ne suit pas les liens. Les commandes ne lisent que les métadonnées des fichiers. Aucune vidéo n’est lancée, aucun APK n’est installé et aucun fichier de tablette n’est créé, déplacé ou supprimé.

Délais : huit secondes maximum par commande et quarante secondes par diagnostic. Réponse JSON limitée à 48 000 octets avant l’enveloppe MCP. Les erreurs incluent `device_not_authorized`, `device_offline`, `not_found`, `permission_denied`, `run_as_unavailable`, `command_timeout`, `command_output_limit`, `location_unconfigured` et les sorties Android non reconnues. Elles ne sont pas remplacées par des valeurs inventées.

## Admission unifiée des transports ADB — 19 novembre 2026

À la lecture des définitions (registre SQL, configuration PHP ou templates partagés), tout sélecteur `device` ou `serial` d’une action `android.*` devient un `android_serial`, sans enum historique. Les autres paramètres et les enums des actions non Android ne changent pas. Les anciennes valeurs peuvent rester stockées mais ne sont ni publiées comme choix fermés ni utilisées pour autoriser un appareil. Aucune migration de schéma n’est nécessaire.

Le moteur synchrone et le worker relisent l’inventaire ADB avant l’exécution. Une disparition ou un changement d’état entre la mise en file et l’exécution fait échouer le job sans lancer l’action. Les diagnostics et les deux installateurs utilisent le même contrôle d’état, sans deuxième liste. Les identifiants mDNS (`adb-…._adb-tls-connect._tcp`), IPv4:port et IPv6:port sont acceptés littéralement, uniquement lorsqu’ils figurent en état `device` dans cet inventaire. La sélection explicite d’une cible reste obligatoire ; aucun lien automatique entre une série USB et un alias Wi-Fi n’est inventé. L’authentification ADB, les limites des diagnostics et les permissions Android, notamment `run-as`, restent en vigueur.
