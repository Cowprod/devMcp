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

## Suivi des écarts dans devMCP

| Domaine | Constat dans le code | Correction et état |
| --- | --- | --- |
| Tables métier et nommage | Les catalogues de thèmes, modèles, runtimes et projets utilisent `dm_*` et des colonnes génériques. Voir la cartographie des cibles `T_*` et des préfixes à trois lettres. | Préparer le pilote thèmes et affectations. Renommage réel en attente de validation du plan. |
| Historisation | `ThemeCatalogue::select/installLocal` et `TemplateCatalogue::select/markApplied` modifient leurs lignes sur place. La journalisation d’actions et les révisions Git ne conservent pas la version complète de la ligne métier. | Ajouter filiation et instantanés avec `historiseTable` dans la transaction métier. Socle testé, conversion du schéma existant en attente. |
| Suppression | L’abandon d’une sélection non appliquée appelle `DELETE` dans les deux catalogues. Plusieurs purges des files et événements sont techniques. | Supprimer logiquement les lignes métier après migration. Documenter les purges techniques par table avec volume et politique de rétention. |
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
