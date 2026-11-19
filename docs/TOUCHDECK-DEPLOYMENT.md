# Exploitation de devMcp sur touchdeck

Dernière observation : 31 octobre 2026, sortie de `systemctl list-units --type=service --all` fournie par l'opérateur. **Inventaire observé**, et non définition exhaustive de l'installation.

## Services systemd réellement observés

| Unité | État observé | Rôle |
| --- | --- | --- |
| `devmcp-tunnel.service` | active/running | Secure MCP Tunnel vers ChatGPT ; transport STDIO côté serveur |
| `devmcp-worker.service` | active/running | Worker des tâches locales |
| `devmcp-pm2.service` | active/exited | Gestionnaire PM2 dédié à devMcp (état systemd exited normal possible pour une unité oneshot/forking ; inspecter le type avant de conclure) |
| `devmcp-update.service` | inactive/dead | Mise à jour depuis la branche GitHub approuvée |
| `devmcp-update-request.service` | inactive/dead | Dispatch des demandes de mise à jour |
| `devmcp-admin-projects.service` | inactive/dead | Publication du catalogue de projets pour l'admin HTTPS |
| `devmcp-admin-android.service` | inactive/dead | Inventaire ADB / diagnostics MultiCam |

**Ne pas utiliser `devmcp-http.service` comme nom de service de production** : cette unité n'était pas installée sur touchdeck au 31 octobre 2026. Le port 127.0.0.1:8765 ne présentait aucun listener lors du contrôle `ss`. Le dépôt décrit un *template* HTTP, pas nécessairement un service déployé.

## Auto-update du serveur MCP

Le serveur expose `devmcp.update` sur le projet unique `devmcp` :
`action_run(project="devmcp", action="devmcp.update")`.
Le projet applicatif TouchDeck est un projet ordinaire, sans rôle dans la mise à jour du serveur.
Le serveur et son administration partagent désormais ce seul projet ; l’ancien identifiant `devmcp-admin` est retiré du registre, sans alias.
La migration SQL retire le grant historique d'auto-update des projets applicatifs.
La réponse confirme uniquement la mise en file ; vérifier ensuite `action_list` et les journaux de déploiement.
L'updater synchronise les diagnostics Android dans le registre SQL avant le redémarrage du runtime.
Les projets absents et actions non autorisées renvoient désormais des erreurs d'outil explicites,
`PROJECT_UNAVAILABLE` / `ACTION_UNAVAILABLE`, et une trace `action_rejected` sans arguments sensibles.

## Vérification et redémarrage

Avant toute action, vérifier l'unité réelle et son type avec `systemctl cat devmcp-tunnel.service` et `systemctl status devmcp-tunnel.service`. Pour recharger le tunnel après modification de la configuration, l'opérateur peut utiliser `sudo systemctl restart devmcp-tunnel.service`, **avec interruption temporaire de la connexion ChatGPT**. Ce redémarrage n'est pas une preuve que la configuration est prise en compte : le vérifier par un appel MCP effectif et les journaux de l'unité. Ne pas redémarrer le worker ou PM2 sans motif.

## Création de projet : prérequis et diagnostic

- Base de données distribuée : `devmcp_distributed`, DSN défini dans `/etc/devmcp/mcp-distributed.php`.
- Migration requise : `migrations/20261031_project_provisioning.sql` ; table `dm_project_provisioning`. Migration appliquée manuellement le 31 octobre 2026 ; `project_provisioning_list` a ensuite retourné `available: true`.
- Configuration root-controlled : `/etc/devmcp/global.php`, bloc `project_provisioning` avec `enabled=true` et `local_workspace_root=/srv/devmcp/workspaces`. La modification a été déclarée par l'opérateur, mais sa prise en compte par le processus MCP reste à confirmer.
- Dépôt privé : politique `github_repository_creation` et identité GitHub autorisées, distinctes des droits de clonage SSH.
- Clonage : racine préexistante, canonique et accessible au compte `devmcp` ; accès SSH GitHub non interactif avec hôte vérifié.
- `project_create_preview` est **non mutateur** et ne vérifie ni les droits GitHub ni l'existence du répertoire.
- Un résultat `UNAVAILABLE` lors de `project_create` ne démontre pas l'absence d'effets partiels. Inspecter le journal SQL et GitHub avant toute nouvelle tentative.

## Administration Web

Le formulaire intégré appelle `http://127.0.0.1:8765/mcp` via `McpProjectCreationClient`. Si aucun processus n'écoute sur ce port, la création Web échouera indépendamment de la création depuis ChatGPT via le tunnel STDIO. Le déploiement d'un transport HTTP local doit être décidé et vérifié séparément ; **ne pas supposer qu'il existe**.

## Vérification d'exploitation après changement

1. Confirmer que le tunnel est reconnecté avec une action MCP de lecture seule (`project_list`).
2. Vérifier `project_provisioning_list` puis `project_create_preview` et examiner le champ `next_step`.
3. En cas de panne, consulter `journalctl -u devmcp-tunnel.service` sur touchdeck sans publier de secrets.
4. N'effectuer un `project_create` qu'après contrôle des prérequis et de l'absence d'effets partiels d'un essai précédent.

## Synchronisation d’un projet depuis MCP

`workspace.sync` est asynchrone : utiliser `action_start` avec `project`,
`action="workspace.sync"` et `arguments={"commit":"<SHA Git complet de 40 caractères>"}`,
puis consulter `job_status` et `job_output`. Résoudre d’abord la branche distante en SHA.
Un appel via `action_run` renvoie `ASYNC_ACTION_REQUIRED` avec `next_tool=action_start`
et une trace `action_rejected`, sans exécuter de commande.
La synchronisation vérifie origin, refuse les fichiers suivis modifiés et utilise
un checkout sans force. La HEAD détachée sur le commit demandé est intentionnelle.

## Connexions SQL après inactivité

Le tunnel STDIO garde ses schémas MCP mais crée un runtime et une connexion SQL neufs
pour chaque appel aux outils de projet. Une expiration MariaDB `wait_timeout` ne bloque
ainsi pas les appels suivants. Aucune action n’est rejouée automatiquement.
Une impossibilité d’ouvrir le registre renvoie `REGISTRY_UNAVAILABLE` sans exposer
les informations de connexion ; `host_capabilities` reste indépendant du registre.
