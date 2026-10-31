# Registre SQL unique des projets devMcp

## Principe

MariaDB (base `devmcp_distributed`) est la source de vérité des projets :

- Projets locaux : `dm_local_project_config` (définition JSON validée de chaque projet).
- Actions locales mutualisées : `dm_shared_action_config` (templates JSON).
- Projets et déploiements distants : tables existantes `dm_project`, `dm_machine`, `dm_deployment`.
- Journal de création / état local : `dm_project_provisioning`.
- Bascule de migration : `dm_project_registry_state`, ligne `id=1, ready=1`.

Aucun changement de comportement n'est autorisé tant que le drapeau de migration n'est pas validé dans la même transaction que les définitions SQL. Les éléments `projects` et `shared_actions` de `/etc/devmcp/global.php` sont alors **ignorés** par le runtime, par le worker, par le doctor et par l'exporteur Admin. Le fichier reste nécessaire pour les paramètres techniques, les chemins, les limites et les secrets ; il sert aussi de référence de retour arrière.

Le registre SQL est relu lors de l'accès aux projets. Une création ou une révocation devient visible pour `project_list`, `action_list`, `action_run`, `action_start` et le worker sans redémarrage du tunnel.

## Migration initiale

Le script d'importation se lance depuis le checkout installé :

```sh
sudo /usr/bin/php /opt/devmcp/bin/import-global-projects-to-sql.php --execute MIGRATE_PROJECTS_TO_SQL
```

Cette opération est aussi intégrée à `deploy/self-update.sh`, **avant** le diagnostic et le redémarrage des services. Elle crée les trois tables à partir de `migrations/20261031_sql_project_registry.sql`, copie les projets et actions de `global.php`, ajoute les anciens projets provisionnés localement en état `active`, puis insère le drapeau `ready=1`. L'étape DDL est idempotente ; les insertions et le drapeau sont transactionnels. Si une entrée SQL préexistante entre en collision, l'import échoue sans écraser des données.

Le script est idempotent une fois `ready=1` : aucune définition SQL n'est réimportée ni écrasée. Il ne modifie jamais `global.php`.

### Validation

Depuis ChatGPT, sur la connexion MCP actualisée :

1. `project_list` doit retourner les projets locaux historiques, les projets distants et les projets déjà provisionnés.
2. `action_list(project="devmcp-e2e-f4cef2ae98247d11d632")` doit exposer les actions standard.
3. `action_run(project="devmcp-e2e-f4cef2ae98247d11d632", action="git.status")` doit réussir.
4. Un nouveau `project_create` doit retourner `state="active"` et `actions_authorized=true`, puis permettre immédiatement une lecture Git.

Les nouvelles créations autorisent uniquement les six actions standard `git.status`, `git.head`, `workspace.list`, `workspace.stat`, `workspace.read` et `workspace.find`. Les commandes génériques en écriture ne sont jamais autorisées implicitement ; leur activation relève des définitions SQL.

### Incidents / retour arrière

Si la migration ne se termine pas, `ready=1` n'est pas commité : le runtime conserve les définitions PHP historiques. Ne supprimer aucune des tables ni aucune donnée sans sauvegarde. En cas de panne après `ready=1`, conserver la configuration et inspecter les définitions SQL et le journal du service de mise à jour avant toute intervention.

Les déploiements et politiques d'exécution des agents distants ne sont pas modifiés par cette migration.
