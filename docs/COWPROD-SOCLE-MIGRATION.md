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

Le pilote déployé à la révision `1757946` couvre le catalogue des thèmes et
leurs affectations, avec le socle `a3524fe`. Le contrôle de bascule a retrouvé
deux thèmes et sept affectations. La génération d’un projet PHP a également
été vérifiée sur TouchDeck. Cette génération est un usage distinct du socle.

La mise en conformité du code métier de devMCP reste le chantier prioritaire.
Le catalogue des modèles, les runtimes, les données métier des projets et
leurs interfaces d’administration restent à traiter. La cartographie SQL
recense les propositions pour les tables techniques sans valider d’exception.

## Suivi des écarts dans devMCP

| Domaine | Constat dans le code | Correction et état |
| --- | --- | --- |
| Tables métier et nommage | Les catalogues des modèles, des runtimes et des projets utilisent encore des tables `dm_*` et des colonnes génériques. | Les thèmes utilisent `T_THEME` et `T_PROJECTTHEME`. Les autres cibles et préfixes sont proposés dans la cartographie. |
| Historisation | `TemplateCatalogue::select/markApplied` et `ProjectRuntimeCatalog::select` modifient encore les lignes sur place. | Filiation et instantanés en service pour les affectations de thèmes. Étendre aux autres écritures métier. |
| Suppression | L’abandon d’un modèle non appliqué exécute encore un `DELETE`. Plusieurs purges des files et événements sont techniques. | Suppression logique en service pour les thèmes. Documenter les purges techniques par table avec volume et politique de rétention. |
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

## Lot préparé sur la branche dédiée — catalogue des modèles de devMCP

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

### Bascule proposée pour les modèles

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
