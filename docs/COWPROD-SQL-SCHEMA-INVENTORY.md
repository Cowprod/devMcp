# Cartographie SQL devMCP — proposition de migration Cowprod

Le relevé en lecture seule de TouchDeck du 28 novembre 2026 couvre
**36 tables physiques InnoDB**. Il comprend les 30 tables d’origine et les
six tables Cowprod déjà actives. Les paires thèmes, modèles et runtimes
conservent leurs anciennes tables pour le retour arrière. Le relevé a
retrouvé 37 colonnes participant à des clés étrangères et aucun trigger.

Chaque table d’origine figure dans le périmètre. La nomenclature des tables
et colonnes est décrite dans [le mapping JSON](cowprod-sql-rename-map.json).
Les schémas réellement implémentés y sont indiqués par `target_columns` et
leur configuration d’activation. Les autres noms restent des propositions.

## Correspondance des tables

| Table actuelle | Table proposée | Préfixe de colonnes | Nature présumée | Références FK déclarées |
| --- | --- | --- | --- | --- |
| `dm_machine` | `T_MACHINE` | `MAC` | Infrastructure | — |
| `dm_project` | `T_PROJECT` | `PRO` | Catalogue fonctionnel | — |
| `dm_deployment` | `T_DEPLOYMENT` | `DEP` | Infrastructure | `dm_machine`, `dm_project` |
| `dm_action_template` | `T_ACTIONTEMPLATE` | `ACT` | Infrastructure | — |
| `dm_deployment_action` | `T_DEPLOYMENTACTION` | `DPA` | Infrastructure | `dm_deployment`, `dm_action_template` |
| `dm_agent_identity` | `T_AGENTIDENTITY` | `AGI` | Sécurité | `dm_machine` |
| `dm_job` | `T_JOB` | `JOB` | File de jobs | `dm_deployment`, `dm_action_template`, `dm_policy_revision` |
| `dm_job_attempt` | `T_JOBATTEMPT` | `JAT` | File de jobs | `dm_job`, `dm_machine` |
| `dm_job_event` | `T_JOBEVENT` | `JEV` | Journal technique | `dm_job` |
| `dm_audit_event` | `T_AUDITEVENT` | `AUD` | Audit technique | — |
| `dm_policy_revision` | `T_POLICYREVISION` | `POL` | Sécurité | `dm_deployment` |
| `dm_enrollment_invitation` | `T_ENROLLMENTINVITATION` | `ENI` | Sécurité | `dm_machine` |
| `dm_workspace_diagnostic` | `T_WORKSPACEDIAGNOSTIC` | `WDG` | Diagnostic technique | `dm_deployment`, `dm_machine` |
| `dm_remote_readonly_job` | `T_REMOTEREADONLYJOB` | `RRJ` | File de jobs | `dm_deployment`, `dm_machine` |
| `dm_remote_hosting_job` | `T_REMOTEHOSTINGJOB` | `RHJ` | File de jobs | `dm_deployment`, `dm_machine` |
| `dm_remote_project` | `T_REMOTEPROJECT` | `RPR` | Infrastructure | `dm_deployment`, `dm_machine` |
| `dm_remote_project_job` | `T_REMOTEPROJECTJOB` | `RPJ` | File de jobs | `dm_machine` |
| `dm_machine_capability` | `T_MACHINECAPABILITY` | `MCA` | Infrastructure | — |
| `dm_project_provisioning` | `T_PROJECTPROVISIONING` | `PPR` | Infrastructure | — |
| `dm_local_project_config` | `T_LOCALPROJECTCONFIG` | `LPC` | Infrastructure | — |
| `dm_shared_action_config` | `T_SHAREDACTIONCONFIG` | `SAC` | Infrastructure | — |
| `dm_project_registry_state` | `T_PROJECTREGISTRYSTATE` | `PRS` | Infrastructure | — |
| `dm_preview_port_reservation` | `T_PREVIEWPORTRESERVATION` | `PVR` | Infrastructure | — |
| `dm_agent_zerotier_address` | `T_AGENTZEROTIERADDRESS` | `AZA` | Infrastructure | `dm_machine` |
| `dm_runtime_type` | `T_RUNTIMETYPE` | `RTY` | Catalogue fonctionnel | — |
| `dm_project_runtime` | `T_PROJECTRUNTIME` | `PRT` | Infrastructure | `dm_runtime_type` |
| `dm_theme` | `T_THEME` | `THE` | Catalogue fonctionnel | — |
| `dm_project_theme` | `T_PROJECTTHEME` | `PTH` | Catalogue fonctionnel | `dm_theme` |
| `dm_template` | `T_TEMPLATE` | `TPL` | Catalogue fonctionnel | — |
| `dm_project_template` | `T_PROJECTTEMPLATE` | `PTP` | Catalogue fonctionnel | `dm_template` |

Les préfixes à trois lettres sont uniques. Les cibles de thèmes, modèles et
runtimes sont actives sur TouchDeck. Le registre `T_PROJECT` et les capacités
`T_MACHINECAPABILITY` sont préparés dans le code et attendent leur migration
en production.

## Colonnes actuellement déclarées (pour établir la correspondance exhaustive)

Le relevé ci-dessous intègre aussi l'ajout `dm_job.policy_revision_id` de la migration 002. Les modifications de type d'une colonne dans une migration ultérieure ne sont pas assimilées à des colonnes supplémentaires.

- **`dm_machine`** (8) : `id`, `name`, `os_family`, `architecture`, `agent_version`, `status`, `last_seen_at`, `created_at`.
- **`dm_project`** (4) : `id`, `description`, `repository_url`, `created_at`.
- **`dm_deployment`** (6) : `id`, `machine_id`, `project_id`, `workspace_path`, `enabled`, `created_at`.
- **`dm_action_template`** (5) : `id`, `revision`, `description`, `risk`, `definition_sha256`.
- **`dm_deployment_action`** (4) : `deployment_id`, `action_id`, `action_revision`, `enabled`.
- **`dm_agent_identity`** (6) : `id`, `machine_id`, `credential_hash`, `expires_at`, `revoked_at`, `created_at`.
- **`dm_job`** (11) : `id`, `deployment_id`, `action_id`, `action_revision`, `arguments_json`, `status`, `idempotency_key`, `expires_at`, `created_at`, `updated_at`, `policy_revision_id`.
- **`dm_job_attempt`** (8) : `id`, `job_id`, `machine_id`, `fencing_token`, `lease_expires_at`, `started_at`, `finished_at`, `exit_code`.
- **`dm_job_event`** (5) : `id`, `job_id`, `event_type`, `details_json`, `created_at`.
- **`dm_audit_event`** (8) : `id`, `actor`, `event_type`, `object_type`, `object_id`, `outcome`, `details_json`, `created_at`.
- **`dm_policy_revision`** (5) : `id`, `deployment_id`, `revision_number`, `policy_sha256`, `approved_at`.
- **`dm_enrollment_invitation`** (6) : `id`, `machine_id`, `token_hash`, `expires_at`, `consumed_at`, `created_at`.
- **`dm_workspace_diagnostic`** (6) : `deployment_id`, `machine_id`, `status`, `branch`, `commit_sha`, `inspected_at`.
- **`dm_remote_readonly_job`** (10) : `id`, `deployment_id`, `machine_id`, `action_name`, `arguments_json`, `state`, `created_at`, `claimed_at`, `finished_at`, `result_json`.
- **`dm_remote_hosting_job`** (11) : `id`, `deployment_id`, `machine_id`, `action_name`, `request_json`, `state`, `created_at`, `expires_at`, `claimed_at`, `finished_at`, `result_json`.
- **`dm_remote_project`** (9) : `project_id`, `deployment_id`, `machine_id`, `repository`, `workspace_path`, `config_json`, `config_revision`, `ready`, `metadata_json`.
- **`dm_remote_project_job`** (12) : `id`, `project_id`, `machine_id`, `deployment_id`, `operation`, `request_json`, `state`, `created_at`, `claimed_at`, `finished_at`, `expires_at`, `result_json`.
- **`dm_machine_capability`** (4) : `machine_key`, `hosting`, `cordova_android`, `cordova_ios`.
- **`dm_project_provisioning`** (12) : `project_id`, `description`, `repository`, `repository_mode`, `state`, `repository_confirmed`, `machine`, `workspace_path`, `actions_enabled`, `revision`, `created_at`, `updated_at`.
- **`dm_local_project_config`** (3) : `project_id`, `config_json`, `updated_at`.
- **`dm_shared_action_config`** (3) : `action_id`, `config_json`, `updated_at`.
- **`dm_project_registry_state`** (3) : `id`, `ready`, `migrated_at`.
- **`dm_preview_port_reservation`** (4) : `project_id`, `machine`, `port`, `created_at`.
- **`dm_agent_zerotier_address`** (3) : `machine_id`, `ipv4`, `observed_at`.
- **`dm_runtime_type`** (5) : `code`, `label`, `protocol`, `supervisor`, `selectable`.
- **`dm_project_runtime`** (8) : `project_id`, `runtime_type`, `desired_state`, `entrypoint`, `document_root`, `health_path`, `revision`, `updated_at`.
- **`dm_theme`** (6) : `id`, `name`, `project`, `repository`, `preview`, `is_default`.
- **`dm_project_theme`** (3) : `project_id`, `theme_id`, `installed_sha`.
- **`dm_template`** (6) : `id`, `name`, `project_type`, `source_project`, `repository`, `is_default`.
- **`dm_project_template`** (4) : `project_id`, `template_id`, `source_sha`, `applied_sha`.

## Convention proposée pour les colonnes

- `XXX_CH_*` pour chaînes, clés textuelles, données JSON et hashes ; `XXX_N_*` pour les numériques ; `XXX_BL_*` pour les booléens ; `XXX_DT_*` pour les dates/horodatages. Exemple : `dm_deployment.enabled` → `DEP_BL_ENABLED`, `dm_job.created_at` → `JOB_DT_CREATED_AT`, `dm_machine.name` → `MAC_CH_NAME`.
- Les identifiants existants `CHAR(32)` ou `VARCHAR(64)` ne deviennent **pas** des nombres par un simple renommage. Exemple : `dm_machine.id` → `MAC_CH_ID` si une exception aux PK `XXX_N_ID` est approuvée. Le choix alternatif d'une nouvelle PK numérique exige un projet de migration des identifiants et de toutes les FK ; il n'est pas implicitement autorisé.
- Les PK textuelles composées (`dm_deployment_action`, `dm_action_template`) ou basées sur une clé métier (`dm_project_runtime`, etc.) nécessitent une décision explicite ; ne pas créer d'identifiants redondants pour satisfaire artificiellement le schéma.
- Les identifiants `BIGINT AUTO_INCREMENT` existants (`dm_job_event.id`, `dm_audit_event.id`) peuvent devenir directement `JEV_N_ID` et `AUD_N_ID` sans changer leur valeur.
- Les propriétés HTTP/JSON, les noms de paramètres d'action MCP, les ID persistants des machines/agents/jobs et les clés des structures PHP ne doivent pas changer avec les noms SQL ; les couches SQL devront conserver des alias de résultat explicites lorsque l'API l'exige.
- Les tables de journal, de file de jobs, de session/sécurité et d'audit ne doivent **pas** recevoir automatiquement l'historisation métier ni le soft delete. Toute exception à la règle métier sera documentée et validée avant migration.

## Dépendances et risques confirmés dans le code

1. **Agent et registre distribué** : `dm_machine`, `dm_deployment`, `dm_project`, `dm_agent_identity`, `dm_job` et leurs FK sont utilisés par les protocoles d'agents, les attributions et les files de jobs. Les changements doivent être coordonnés avec le code de chaque accès et les migrations d'installation.
2. **Contrat de schéma strict** : `src/Distributed/Admin/HostingLabSchemaMigration.php` vérifie explicitement les noms et types de colonnes de `dm_remote_hosting_job` et l'état de sa liste ENUM. Un simple `RENAME TABLE` casserait ce contrôle.
3. **Transactions, locks et index** : préserver `FOR UPDATE`, le verrouillage MySQL, les index de leasing/fencing, les contraintes d'unicité d'idempotence et les références FK. Le schéma est en InnoDB ; le DDL MariaDB/MySQL n'est pas une transaction applicative atomique.
4. **Migrations existantes** : les migrations historiques `CREATE TABLE IF NOT EXISTS`, `ALTER TABLE`, les migrations automatiques d'autoupdate et les scripts d'initialisation doivent rester compatibles avec une installation vierge comme avec une installation déjà déployée. Ne pas simplement remplacer les noms dans les anciennes migrations déjà exécutées.
5. **Deux arbres de migrations** : `database/migrations` (9 scripts) et `migrations` (7 scripts) doivent être migrés ensemble, avec leurs entrées `bin/migrate-*.php`, leurs tests et les scripts de droits `deploy/distributed/grant-*.sql`.
6. **Privilèges** : les instructions GRANT et les politiques des connexions MySQL doivent être revues si elles désignent des objets renommés.
7. **Dépendances externes** : vérifier les accès depuis les agents, scripts root, jobs systemd, autres installations éventuelles et tâches planifiées ; ne pas présumer que les seuls consommateurs sont les classes PHP.

## Lots couvrant les trente tables d’origine

| Lot | Tables d’origine | Scripts et contrats à migrer avec les tables |
| --- | --- | --- |
| Thèmes, modèles, runtimes — en production | `dm_theme`, `dm_project_theme`, `dm_template`, `dm_project_template`, `dm_runtime_type`, `dm_project_runtime` | Catalogues PHP, administration, contrôleur systemd, notifications, initialisation et retour |
| Registre des projets — préparé | `dm_project` | Provisionnement, administration, inscription lab et clé étrangère des déploiements |
| Capacités des machines — préparé | `dm_machine_capability` | Création et édition Web/mobile, commande MCP, initialisation, auto-update et droits SQL |
| Déploiements et configuration | `dm_deployment`, `dm_remote_project`, `dm_project_provisioning`, `dm_local_project_config`, `dm_shared_action_config`, `dm_project_registry_state`, `dm_preview_port_reservation` | Provisionnement local/distant, import SQL, démarrage systemd, migration multi-déploiements, contrôle des réservations et droits |
| Machines, identités et politiques | `dm_machine`, `dm_agent_identity`, `dm_enrollment_invitation`, `dm_action_template`, `dm_deployment_action`, `dm_policy_revision` | Enrôlement, révocation, contrôle des actions autorisées, droits SQL et clés étrangères de la file |
| Files et journaux | `dm_job`, `dm_job_attempt`, `dm_job_event`, `dm_remote_readonly_job`, `dm_remote_hosting_job`, `dm_remote_project_job`, `dm_audit_event` | Polling, transitions, verrous, retries, expiration, anti-doublon, journaux, requêtes de notifications et mécanismes de reprise |
| Observations des agents | `dm_workspace_diagnostic`, `dm_agent_zerotier_address` | Ping, diagnostic, expiration et décisions basées sur la fraîcheur de l’observation |

Les tables techniques sont incluses dans le chantier de nommage et de revue
des écritures. Chaque proposition d’exception à l’historisation ou à la
suppression logique doit indiquer la table, le parcours concerné et sa
justification. Les règles d’autorisation et les identifiants des agents ou
jobs restent compatibles pendant la bascule.

## Vérification avant et après chaque lot

`php bin/check-cowprod-sql-schema.php --details` relève les objets, colonnes,
index, clés étrangères et triggers sans lire les données métier. Le rapport
résume les états hérités, préparés et actifs. Les migrations d’installation,
les droits, les commandes, les workers et les pilotes d’auto-update font
partie de la recette de chaque lot. Les noms effectivement lus ou écrits
peuvent être retrouvés par `git grep -n -w NOM_TABLE -- src bin public
deploy database migrations tests`.

Comparer les comptes et les relations sur une restauration isolée et
vérifier une installation vierge et une mise à jour. Répéter un retour
arrière avec des écritures effectuées après bascule avant de modifier la
production.
