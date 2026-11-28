# Mise en conformité de devMCP avec le socle Cowprod

## Référence et périmètre

Les règles de `Cowprod/referenciel` s’appliquent au code métier devMCP. Le présent
chantier n’est pas le Lab Monitor et ne dépend pas de `lab-monitor-schema`.
La [cartographie SQL](COWPROD-SQL-SCHEMA-INVENTORY.md) recense les 30 tables des
migrations, leurs colonnes et leurs dépendances. Elle contient des propositions de
renommage, pas une décision de les déployer. Le relevé des migrations doit être
comparé à la structure réelle en lecture seule avant tout DDL.
Le 27 novembre, le précontrôle en lecture seule de TouchDeck a retrouvé les
30 tables attendues et leurs colonnes, sans divergence sur ces noms. Ce
contrôle ne couvre pas encore les types, contraintes, index, triggers et vues.

## État du chantier sur TouchDeck

Le pilote des thèmes, déployé à la révision `1757946`, a repris deux thèmes et
sept affectations. La révision `13f6c77` a repris les deux modèles et leurs deux
affectations, avec comparaison des SHA source et appliqués. Les deux modules
utilisent le socle `a3524fe`. La révision `79dc1ac` a ensuite repris
les quatre types de runtime et leurs dix-huit configurations.

Les tables métier du registre des projets sont le prochain lot prioritaire.
La mise en conformité de l’administration Web reste à traiter. La cartographie SQL recense les propositions
pour les tables techniques sans valider d’exception.

## Suivi des écarts dans devMCP

| Domaine | Constat dans le code | Correction et état |
| --- | --- | --- |
| Tables métier et nommage | Le registre des projets utilise encore des tables `dm_*` et des colonnes génériques. | Les thèmes, modèles et runtimes utilisent leurs tables `T_*` en production. Les autres cibles sont dans la cartographie. |
| Historisation | Les mises à jour des fiches du registre restent à historiser. | Filiation et instantanés en service pour les affectations de thèmes, de modèles et de runtimes. |
| Suppression | Plusieurs purges des files et événements sont techniques. | Suppression logique en service pour les affectations de thèmes, de modèles et de runtimes. Documenter les purges techniques par table. |
| Signatures | Sessions d’admin, audit, identité d’agent et horodatages existent. Les lignes de catalogue ne portent pas toutes les signatures de création et de suppression. | Récupérer l’identité vérifiée à la frontière métier. Faire approuver le traitement des écritures d’agent. Ne jamais inventer une signature historique manquante. |
| SQL métier | `src/Project/ThemeCatalogue.php`, `TemplateCatalogue.php`, `ProjectRuntimeCatalog.php` et certaines requêtes de `public/distributed-admin.php` utilisent des requêtes inline, des alias courts et du binding. | Migrer progressivement vers `.sql` + `oRs()` et helpers de préparation. Garder les contrôles existants en technique jusqu’à revue des exceptions. |
| Identifiants | Les clés texte de projets, machines et jobs sont des contrats MCP/agent. L’admin en expose certaines dans les liens et données de formulaires ou AJAX. | Séparer identifiant navigateur et clé d’API, déchiffrer puis refaire le contrôle d’accès côté serveur. Fournir la clé externe par configuration. Préserver les identifiants MCP, ZeroTier, agents et artefacts. |
| Organisation PHP | Admin distribué volumineux et vues mêlées aux traitements. Les composants métier ne suivent pas systématiquement `index.php`, `mod*.php`, `sel.php`, `trUpd*.php`, `trSup*.php`. | Extraire un module à la fois. Maintenir les routes MCP et agent existantes. Réutiliser les helpers du socle. |
| Frontend | L’admin distribué charge ses propres éléments d’interface et traitements AJAX. La présence de Bootstrap ne garantit pas Quartz, Font Awesome ni les conventions des formulaires. | Auditer écran par écran. Reprendre les boutons, groupes de champs, sélecteurs, retours d’autosave, boutons d’administration et rafraîchissements de fragments dans le pilote. |
| Technique | Files, tentatives, événements, jetons, invitations, politiques, déploiements et télémétrie ont des invariants spécifiques de reprise, volume ou sécurité. | Les exceptions sont à décider par table et à faire approuver. Aucune modification de l’authentification ou des protocoles agent/MCP dans ce lot. |

Les entrées de catalogue éditées par un opérateur et leurs affectations relèvent du
métier. Une file de jobs, la progression ou un heartbeat ne deviennent pas métier
parce qu’ils sont affichés dans le navigateur. Le catalogue projet existant reste
l’autorité. Aucun deuxième registre n’est créé.

## Ordre de migration proposé

1. Extraire en lecture seule le schéma réel et rapprocher les 30 tables de leurs
   lecteurs et écrivains. Regrouper les écarts de structure, les DDL historiques,
   les droits et les dépendances de clés étrangères.
2. Répéter sur une copie isolée pour `dm_theme` et `dm_project_theme`.
   Faire valider la correspondance proposée `T_THEME` / `T_PROJECTTHEME`
   et les préfixes. Conserver les slugs externes en colonnes métier, ajouter
   des clés numériques internes et filiation, et garantir une affectation active
   par projet avec une clé unique conditionnée au statut actif. Renseigner
   l’identité historique à partir d’une source attestée seulement.
3. Convertir le module thèmes sur la copie avant de changer le schéma réel.
   Dans la transaction, verrouiller la mère, historiser puis modifier avec les
   helpers. Une suppression logique garde directement la ligne. Tester reprise,
   concurrence, droits, rollback, liens existants et réponses MCP inchangées.
4. Après validation explicite du lot, sauvegarder, relever les comptes et
   invariants puis basculer dans une fenêtre avec écritures suspendues. Comparer
   les résultats avec le relevé. Le retour arrière doit préserver les écritures
   acceptées après bascule. Sans procédure de reprise démontrée, garder les
   écritures suspendues ou ne pas basculer.
5. Étendre aux autres catalogues par lots. Examiner les tables techniques
   séparément avec les propriétaires des agents et contrats MCP.

## Génération et mise à jour des projets PHP

Le modèle `php-cowprod` s’insère dans le catalogue de modèles existant. Son
projet source est le clone local enregistré de `Cowprod/referenciel`. Seuls des
SHA complets présents dans la branche amont approuvée `origin/main` sont acceptés.
Le modèle requiert que la version complète du socle soit d’abord validée dans le
référentiel. La version utilisée pour la recette isolée est
`a3524fea59075ea6870693a8b50450dfa92fff07`. Cette branche devMCP ne change aucun schéma existant. L’entrée du
catalogue de modèles est une donnée initiale de la migration déjà en place.

Pour une création intégrée, appeler `project_create` avec `project_type: "php"`,
`template_sha` et la confirmation habituelle. Le socle est contrôlé avant la
création GitHub, puis avant sa publication dans le nouveau dépôt privé. Le
catalogue enregistre le SHA source et le SHA publié. Pour une machine distante,
initialiser d’abord le dépôt PHP sur devMCP puis déployer ce même dépôt sur
l’agent. L’ancienne création générique reste disponible pour les autres projets.

Le projet généré reçoit le code, les assets, les exemples de config, les
conventions, `AGENTS.md`, un verrou de provenance et un contrôle CI de syntaxe
et d’intégrité. Son document root est `public/`. Le vrai répertoire `config/`
reste hors du document root et ses fichiers réels hors Git. Les dépendances
facultatives SMTP, SMS et édition riche sont installées par le consommateur.

Après avoir récupéré un nouveau SHA validé dans le clone du référentiel,
`bin/devmcp-socle-php update <référentiel> <sha> <projet>` vérifie et applique
les fichiers gérés. Il refuse les divergences locales sur les fichiers du socle
et les suppressions de fichiers entre versions. Revoir puis committer le diff.
Les modules propres au projet sont hors verrou. Les changements intentionnels
d’un fichier géré exigent une résolution explicite. Le contrôle d’intégrité ne
remplace pas la revue métier du SQL, de l’historisation, des signatures et de la
sécurité d’un module.

En cas d’échec après création du dépôt, le résultat indique
`partial_failure/template_publish`. Le dépôt et le workspace restent disponibles
pour inspection. Vérifier l’état Git et l’affectation existante avant de relancer.

## Capacités des machines en production

Le 28 novembre 2026, `54c6a47` a migré cinq déclarations sur TouchDeck.
Le contrôle du tunnel et les lecteurs Web/MCP ont réussi.

La migration de `dm_machine_capability` vers `T_MACHINECAPABILITY`
(`MCA_`) conserve les ID machine, la valeur `local` et les trois droits
déclarés. Une modification conserve l’état précédent et la signature
authentifiée. Les lectures existantes passent par une vue en lecture seule
après la bascule. Un retrait complet de déclaration n’existe pas dans le
parcours actuel. L’opérateur peut désactiver chaque capacité et le changement
est historisé.

Avant activation, sauvegarder et restaurer les déclarations sur une
instance isolée. Suspendre les créations de machines et les écritures de
capacités, puis exécuter
`migrate-cowprod-machine-capabilities --prepare CAPABILITY_WRITES_QUIESCED`,
`--verify` et `--activate CAPABILITY_WRITES_QUIESCED`. Définir
`cowprod_machine_capabilities` dans les configurations MCP et Web, avec
l’identité de service et la version du socle déjà approuvée. Les lecteurs
doivent pouvoir lire la vue et sa table source. Les rédacteurs reçoivent
SELECT, INSERT et UPDATE sur la nouvelle table. L’auto-update reconnaît ce
mode et saute le seed historique de l’ancienne table.

Vérifier Web, mobile, commande MCP et les parcours de contrôle des
capacités. Rejouer le retour avec `--project-back CAPABILITY_WRITES_QUIESCED`
pendant la suspension des écritures et retirer le réglage après contrôle.
Le retour projette les valeurs courantes et conserve l’historique.

## Registre des projets en production

Le 28 novembre 2026, `54c6a47` a migré 54 fiches et préservé les 23
déploiements ainsi que les 17 services applicatifs actifs. Sauvegardes et
journaux des deux bascules sur TouchDeck dans
`/var/backups/devmcp/registry-capabilities-cowprod-20261128T100335Z.bPOziKH8`.

`T_PROJECT` utilise le préfixe `PRO_`, une clé numérique interne et les
champs Cowprod de filiation, création et suppression. Le slug existant reste
l’identifiant MCP et agent. Une seule ligne mère peut le porter, y compris
après un retrait. Les modifications par l’admin utilisent `historiseTable()`
et l’acteur de la session. Les créations par MCP et par le service d’inscription
du lab utilisent les identités de leurs services.

Le relevé de TouchDeck a trouvé 54 fiches, 23 déploiements et une seule
clé étrangère vers le registre, `dm_deployment.fk_deployment_project`.
La migration accroche cette clé à `T_PROJECT.PRO_CH_ACTIF`, une valeur
générée et stockée pour les seules fiches actives. La compatibilité des
lectures agent, MCP et Web est assurée par une vue en lecture seule du même
nom que l’ancienne table. L’ancienne table est archivée pendant la fenêtre de
retour et ses métadonnées ne sont plus écrites en fonctionnement courant.

Le retrait logique est refusé tant que subsistent un déploiement, une
réservation, un workspace ou une affectation de thème, modèle ou runtime.
Les parcours de provisionnement et les configurations exécutables conservent
leur schéma actuel dans ce lot.

### Bascule du registre

1. Sauvegarder le registre, les déploiements et les configurations distribuées.
   Répéter la reprise avec les données restaurées sur une instance MariaDB
   isolée de la même version. Vérifier les droits réels des lecteurs sur la vue
   et les colonnes sous-jacentes, et ceux des écrivains sur `T_PROJECT`.
2. Déployer le code avec la configuration actuelle. Laisser finir les
   opérations de création et d’affectation en cours. Suspendre les écritures
   d’administration, MCP, agents et workers, ainsi que les timers de mise à
   jour, pendant le transfert de la clé et le changement de configuration.
3. Exécuter `migrate-cowprod-projects --prepare PROJECT_WRITES_QUIESCED`,
   comparer toutes les fiches et les références, puis exécuter
   `--activate PROJECT_WRITES_QUIESCED`. Ajouter `cowprod_projects` aux
   configurations distribuées des transports et de l’inscription du lab.
   Utiliser le socle déjà approuvé et l’identité de service vérifiée.
4. Contrôler la clé étrangère, les permissions et la parité des lectures.
   Rouvrir les transports, puis vérifier la liste des projets, le détail,
   les déploiements et les parcours admin. Prévoir une fiche jetable pour
   vérifier modification, historique et retrait dans la recette.
5. Si un retour est nécessaire, reprendre la suspension des écritures et
   exécuter `--project-back PROJECT_WRITES_QUIESCED` avec le réglage actif.
   Vérifier les fiches et la clé étrangère, retirer le réglage puis rouvrir.
   Les lignes d’historique de `T_PROJECT` sont conservées. Une réactivation
   après des écritures sur le schéma précédent exige leur réconciliation.

Recette sur une instance jetable

```sh
COWPROD_PROJECT_TEST_DSN='mysql:unix_socket=/chemin/jetable/mysql.sock;charset=utf8mb4' \
COWPROD_PROJECT_TEST_SOCLE='/chemin/vers/socle-généré' \
php tests/cowprod-project-registry.php
```

## Réservations de ports en production

La révision `810a79f` a migré 25 réservations sur TouchDeck. La comparaison
des lignes et les lectures MCP ont confirmé les ports existants. Sauvegardes
et résultats dans `/var/backups/devmcp/preview-ports-cowprod-20261128T100945Z.t9sybVTk`.

La table `T_PREVIEWPORTRESERVATION` conserve le port, la machine, le projet
et la date de création. L’ancienne affectation est historisée lors d’un
changement de machine. La libération inscrit une suppression logique. Les
contraintes s’appliquent aux seules réservations actives. Chaque tentative
sur un port en conflit annule son historique et préserve la réservation
précédente avant de réessayer.

Après sauvegarde et recette isolée, suspendre les écrivains du registre.
Exécuter `migrate-cowprod-preview-ports --prepare PORT_WRITES_QUIESCED`,
`--verify` et `--activate PORT_WRITES_QUIESCED`. Activer
`cowprod_preview_ports` dans la configuration distribuée avec l’identité du
service et le socle déjà approuvé. Vérifier les droits SQL ainsi que la
lecture par le provisionnement, l’administration et le contrôleur des
applications. La vue de compatibilité conserve les clés lues par ces
consommateurs. Le retour `--project-back PORT_WRITES_QUIESCED` projette les
allocations actives et conserve les retraits et les versions dans la table
Cowprod.

## Registre des configurations SQL en production

La révision `93c3366` a repris 30 configurations locales, 52 actions
partagées et le marqueur d’installation sur TouchDeck. Les listes d’actions
MCP et les identifiants ont été contrôlés à l’identique. Sauvegardes et
résultats dans `/var/backups/devmcp/sql-project-definitions-cowprod-20261128T101720Z.2KpFmbvi`.

Les tables `T_LOCALPROJECTCONFIG`, `T_SHAREDACTIONCONFIG` et
`T_PROJECTREGISTRYSTATE` reprennent les définitions et le marqueur
d’installation existants. Les scripts d’import, de diagnostics, de
provisionnement et d’auto-update passent par le registre PHP existant.
Les définitions sont historisées avant modification, le retrait d’une
définition locale est logique. Le marqueur d’installation est créé une fois
comme dans le registre d’origine. Les vues conservent les lectures existantes.

Après sauvegarde et recette isolée, suspendre les écrivains du registre.
Exécuter `migrate-cowprod-sql-project-config --prepare SQL_CONFIG_WRITES_QUIESCED`,
`--verify`, puis `--activate SQL_CONFIG_WRITES_QUIESCED`. Activer le réglage
`cowprod_sql_project_config` dans les configurations distribuées et vérifier
la lecture, la mise à jour des actions et le provisionnement. Le retour
`--project-back SQL_CONFIG_WRITES_QUIESCED` préserve les définitions actives
et laisse les historiques dans les tables Cowprod.

## Déploiements préparés sur la branche dédiée

Le relevé de production compte 23 déploiements. La clé projet de
`dm_deployment` référence `T_PROJECT.PRO_CH_ACTIF` et sa clé machine
référence `dm_machine.id`. Sept tables référencent l’identifiant de
déploiement. Leurs contraintes réelles sont documentées dans la cartographie.

`T_DEPLOYMENT` utilise le préfixe `DEP_`, un identifiant numérique interne
et une clé de filiation. L’identifiant de déploiement utilisé par les agents
reste celui de la ligne mère. Les sept clés étrangères pointent vers lui
même après retrait. Les scripts d’exécution voient les affectations actives.
La vue `V_DEPLOYMENTHISTORY` conserve les relations des travaux terminés pour
l’administration, les notifications et le journal MCP.

Les modifications de chemin sont historisées et signées. Un retrait exige
une affectation désactivée, une file sans opération en cours et un workspace
géré détaché. La ligne mère est conservée avec sa signature de retrait. La
vue de compatibilité active utilise MERGE pour conserver les verrous
`FOR UPDATE`. Tous les écrivains applicatifs sont dirigés vers le registre
Cowprod quand le réglage est actif.

### Bascule des déploiements

1. Sauvegarder la base et les configurations distribuées. Répéter la reprise
   et le retour sur une restauration de la base réelle. Vérifier les sept
   contraintes, leurs règles et le contenu des tables enfants.
2. Déployer le code et laisser finir les opérations actives. Suspendre les
   transports et les écrivains. Utiliser
   `migrate-cowprod-deployments --prepare DEPLOYMENT_WRITES_QUIESCED`,
   `--verify` et `--activate DEPLOYMENT_WRITES_QUIESCED`.
3. Ajouter `cowprod_deployments` dans les configurations distribuées des
   écrivains et des lecteurs, avec le socle et l’identité locale de confiance.
   Vérifier clés, permissions, lectures MCP/HTTPS, polling et historique.
4. Le retour utilise `--project-back DEPLOYMENT_WRITES_QUIESCED` après
   suspension des écritures et avant retrait du réglage. Les déploiements
   retirés restent présents et désactivés en ancien mode pour leurs jobs.
   Le retour du registre des projets intervient après celui des déploiements.

La bascule du 28 novembre 2026 a conservé 23 déploiements. Les données des
sept tables enfants sont identiques et les 17 services applicatifs ont gardé
leurs PID. L'API HTTPS authentifiée, le connecteur MCP et une nouvelle
exécution de l'auto-update ont répondu après la bascule. Sauvegardes et
résultats sur TouchDeck dans
`/var/backups/devmcp/deployments-cowprod-20261128T150632Z.q94vZ5AA`.

## Journal de provisionnement

`T_PROJECTPROVISIONING` conserve les identifiants projet, droits, révisions,
dates et réservations de dépôts. Le provisionnement comporte des étapes
antérieures à l'entrée d'un projet dans le catalogue. Il n'a donc pas de clé
étrangère vers `T_PROJECT`. Les réservations d'identifiant et de dépôt sont
uniques au niveau des lignes mères et restent réservées. Le workflow actuel
ne propose aucune suppression. La structure et la vue prévoient la
suppression logique des lignes mères.

Les mutations sont signées par l'acteur MCP de confiance, l'agent authentifié
qui termine le travail ou le service d'import. La copie d'historique précède
la mutation réelle dans la transaction de l'opération. Une révision
périmée ou un second résultat distant identique ne produit aucun historique.

Répéter `migrate-cowprod-provisioning --prepare PROVISIONING_WRITES_QUIESCED`,
`--activate PROVISIONING_WRITES_QUIESCED`, `--verify` et
`--project-back PROVISIONING_WRITES_QUIESCED` sur une restauration. Pour
basculer, suspendre les écrivains, comparer la projection de toutes les
lignes et ajouter `cowprod_project_provisioning` à la configuration
distribuée. Vérifier les droits des comptes, les lectures MCP et
l'activation par un résultat distant dans la recette. Pour revenir, suspendre
les écrivains, projeter les lignes mères vers l'archive puis retirer le
réglage. Le nouveau journal reste présent avec son historique.

## Runtimes de devMCP en production

Les tables cibles sont `T_RUNTIMETYPE` (`RTY_`) et `T_PROJECTRUNTIME`
(`PRT_`). La reprise conserve le moteur, le protocole, le superviseur,
l’état demandé, les chemins, la révision et le dernier horodatage. Les
réservations de ports restent dans la table existante. Les dates et signatures
absentes à l’origine restent à NULL.

Les changements de moteur et d’état demandé appellent `historiseTable()`
avant l’UPDATE, dans la même transaction. Le retrait logique conserve la
ligne mère et permet une nouvelle affectation avec une révision supérieure.
Le retrait est exposé dans l’administration pour un service local arrêté.
La fiche d’administration, l’API mobile, le lanceur et contrôleur systemd
ainsi que les liens des notifications lisent le catalogue actif.

La bascule du 28 novembre 2026 a conservé les 17 unités applicatives actives
avec les mêmes PID. Le contrôle HTTPS de l’administration a réussi. La
restauration et la recette du lanceur en réseau isolé ont réussi sur
MariaDB 10.11.14. Sauvegardes et résultats sur TouchDeck dans
`/var/backups/devmcp/runtime-cowprod-20261128T092445Z.7WjmweI8`.

### Procédure de bascule et retour

1. Sauvegarder les deux tables d’origine et les configurations distribuées
   utilisées par le contrôleur et l’administration. Restaurer et répéter la
   migration sur une base isolée de la même version que la production.
2. Suspendre les écritures des runtimes. Arrêter les timers de contrôle et
   de mise à jour, laisser finir les opérations systemd en cours, puis
   suspendre l’administration et les notifications pendant la bascule. Les
   unités applicatives déjà lancées peuvent continuer de tourner.
3. Exécuter `php bin/migrate-cowprod-runtimes --prepare RUNTIME_WRITES_QUIESCED`
   avec la configuration SQL d’écriture. Exécuter `--verify` avec la
   configuration SQL de lecture via `DEVMCP_DISTRIBUTED_CONFIG`.
4. Activer `cowprod_runtimes` dans les deux configurations distribuées.
   Reprendre le `socle_root` et le `socle_revision` déjà approuvés, et
   renseigner explicitement `mcp_actor` et `service_actor` pour ce serveur.
   Vérifier les droits SQL du lecteur, du contrôleur et de PHP-FPM. Relancer
   les composants suspendus et valider la lecture des runtimes et un
   démarrage/arrêt sur un projet de recette, avec vérification de l’historique.
5. Pour revenir, suspendre à nouveau les écritures et appeler
   `--project-back RUNTIME_WRITES_QUIESCED` avant `--verify`. Retirer le
   réglage des deux configurations après comparaison. Les tables Cowprod
   gardent les lignes historiques et les retraits logiques.

L’auto-update vérifie le catalogue actif. Le DDL Cowprod est exécuté par la
commande de migration explicite. Après un retour au modèle historique, la
préparation refuse une divergence et impose une réconciliation avant réactivation.

Recette sur une instance jetable

```sh
COWPROD_RUNTIME_TEST_DSN='mysql:unix_socket=/chemin/jetable/mysql.sock;charset=utf8mb4' \
COWPROD_RUNTIME_TEST_SOCLE='/chemin/vers/socle-généré' \
php tests/cowprod-project-runtime.php
```

## Catalogue des modèles de devMCP en production

Les noms proposés dans la cartographie sont `T_TEMPLATE` (`TPL_`) et
`T_PROJECTTEMPLATE` (`PTP_`). Préserver les clés texte renvoyées par MCP,
le SHA source du socle et le SHA publié dans le projet. Le projet source de
chaque modèle reste dans le registre de projets existant.

- Migrer toutes les entrées et affectations actives, avec comparaison champ
  par champ. L’ancien schéma ne fournit ni date ni signature pour ces lignes.
- Inventorier les écritures MCP avant bascule, notamment la création intégrée
  d’un projet PHP et la publication d’un modèle. Historiser la sélection et
  l’enregistrement des SHA dans la transaction, avec l’acteur authentifié.
  Appliquer la suppression logique au retrait d’un modèle non appliqué.
- Garder une seule affectation active par projet et le refus de changer un
  modèle déjà appliqué. Conserver les réponses MCP et vérifier les courses
  entre sélection, retrait et enregistrement des SHA.
- Répéter en base isolée la reprise et la projection de retour. Contrôler les
  métadonnées et SHA après des écritures effectuées dans le nouveau catalogue.
  Soumettre le plan de bascule avant toute modification du schéma de production.

### Bascule des modèles

Sauvegardes et traces de recette conservées sur TouchDeck dans
`/var/backups/devmcp/template-cowprod-20261128T083509Z.ZJMAndDJ`. La restauration et la répétition ont été vérifiées sur MariaDB
10.11.14. Les étapes restent applicables aux autres installations.

1. Sauvegarder `dm_template`, `dm_project_template` et le fichier de
   configuration distribuée du tunnel. Laisser finir les publications de
   modèles en cours, puis suspendre les écritures MCP et les mises à jour.
2. Préparer les tables avec
   `php bin/migrate-cowprod-templates --prepare TEMPLATE_WRITES_QUIESCED`.
   Vérifier avec `--verify` en désignant la configuration SQL de lecture via
   `DEVMCP_DISTRIBUTED_CONFIG` si la configuration du tunnel est uniquement
   configurée pour l’écriture.
3. Activer `cowprod_templates` dans la configuration distribuée MCP.
   Utiliser le même `socle_root` et le même `socle_revision` que les thèmes,
   avec l’identité MCP de cette installation dans `mcp_actor`. Le choix du
   modèle est verrouillé pendant la publication Git. Redémarrer le tunnel,
   vérifier sa disponibilité et relire le catalogue et les affectations par MCP.
4. Pour revenir au catalogue précédent, suspendre à nouveau les écritures.
   Projeter les écritures récentes avec `--project-back TEMPLATE_WRITES_QUIESCED`,
   comparer avec `--verify`, retirer le réglage puis relancer le tunnel.
   L’historique des nouvelles tables demeure disponible après le retour.
   L’auto-update contrôle l’accès au catalogue actif. Il ne lance pas ce DDL.

Si Git a publié le commit mais que la validation SQL échoue, le commit reste
sur Git. Vérifier le SHA et l’affectation avant toute relance. Le retour SQL
préserve les écritures validées en base et ne force aucune révision Git.

Recette sur une instance jetable, avec un socle déjà généré

```sh
COWPROD_TEMPLATE_TEST_DSN='mysql:unix_socket=/chemin/jetable/mysql.sock;charset=utf8mb4' \
COWPROD_TEMPLATE_TEST_SOCLE='/chemin/vers/socle-généré' \
php tests/cowprod-template-catalogue.php
```

## Pilote des thèmes en production

Les noms retenus sont `T_THEME` (`THE_`) et `T_PROJECTTHEME` (`PTH_`).
Les slugs publics demeurent les identifiants de projet et de thème dans les
réponses et entrées MCP, HTTP et Web. Les clés numériques de filiation restent
internes et ne sont rendues dans aucune réponse. Les routes et la vérification
des droits des agents et administrateurs continuent d’utiliser leurs contrats
existants. La revue de l’encodage des clés publiques dans l’ensemble de l’admin
reste un lot séparé.

Le pilote utilise les helpers de son socle vérifié, des SELECT métier dans
`src/Project/theme/` et une transaction qui verrouille les opérations d’un même
projet. Une sélection identique ne crée aucun historique. Un retrait autorisé
marque directement la ligne comme supprimée. Le changement d’un thème installé
passe toujours par l’installateur. Un échec SQL restaure l’affectation et appelle
la compensation d’activation si celle-ci avait eu lieu. Le catalogue de thèmes
lui-même est en lecture seule à travers les interfaces actuelles.

Les dates et signatures absentes de l’ancien schéma restent à NULL lors de
l’import. Toute nouvelle création, révision ou suppression reçoit la signature
issue du point d’entrée authentifié. L’admin Web fournit le login de sa session
et l’API d’administration l’identifiant de sa session authentifiée. Le tunnel
MCP utilise une identité de service explicitement configurée dans `mcp_actor`
car le protocole de cette instance ne transmet pas de login humain vérifiable
à ces appels. Les arguments MCP ne permettent pas de fournir cette identité.

### Fenêtre de bascule proposée

1. Préparer une sauvegarde vérifiée et suspendre les écritures de thèmes dans
   le tunnel MCP, l’admin Web et l’API mobile. Suspendre aussi les mises à jour
   automatiques durant la fenêtre. Les tables initiales restent l’autorité
   tant que la configuration distribuée ne contient pas `cowprod_themes`.
2. Installer dans un chemin partagé et administré un socle généré avec
   `bin/devmcp-socle-php` à sa révision validée. L’arbre du socle est en lecture
   seule pour PHP et le serveur web. Configurer les droits SQL nécessaires
   avant activation. Lecteur `SELECT` sur les deux nouvelles tables. Écrivain
   `SELECT, INSERT, UPDATE` et droits de migration pour le compte opérateur.
3. Exécuter `php bin/migrate-cowprod-themes --prepare THEME_WRITES_QUIESCED`
   puis `php bin/migrate-cowprod-themes --verify` avec la configuration
   distribuée. Le premier crée et remplit les deux nouvelles tables. Le
   second compare toutes les lignes métier et SHAs actifs. Un écart bloque
   la bascule. Les DDL sont impliqués dans la fenêtre car MySQL et MariaDB les
   valident séparément de la transaction de copie.
4. Ajouter `cowprod_themes` à la configuration distribuée commune. Fournir
   `socle_root` (chemin absolu), `socle_revision` (SHA complet) et
   `mcp_actor` (principal de service vérifié pour cette installation).
   Déployer le même code sur les surfaces Web et MCP et recharger les
   processus. Contrôler le catalogue et les droits des trois transports
   avant de rouvrir les écritures.
5. En cas de retour, suspendre à nouveau les écritures. Garder
   `cowprod_themes` actif le temps de lancer
   `php bin/migrate-cowprod-themes --project-back THEME_WRITES_QUIESCED`
   et `--verify`. Ce retour répercute les écritures récentes dans les tables
   anciennes, y compris les retraits, et conserve les tables historisées.
   Retirer le réglage seulement après contrôle, puis recharger les processus.
   Ne pas réactiver les tables historisées après des écritures dans l’ancien
   modèle sans réconciliation explicite.

Un seul catalogue est servi à chaque instant. Les anciennes tables conservées
pendant la fenêtre de retour sont une projection figée, sans double écriture.
L’auto-update n’exécute pas la migration du pilote.

### Recette reproductible

```sh
COWPROD_THEME_TEST_DSN='mysql:unix_socket=/chemin/jetable/mysql.sock;charset=utf8mb4' \
COWPROD_THEME_TEST_SOCLE='/chemin/vers/socle-généré' \
php tests/cowprod-theme-pilot.php
```

Le test crée et retire une base au nom aléatoire sur l’instance explicitement
désignée. Il compare les réponses avant et après bascule, exerce sélection,
installation, suppression logique, concurrence, signature et compensation en
cas d’erreur SQL, puis vérifie le retour avec conservation de l’historique.
