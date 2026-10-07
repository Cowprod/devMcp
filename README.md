# devMcp

MCP PHP générique servant de plan d’exécution contrôlé pour les projets Cowprod.

Le but est de permettre à ChatGPT de piloter les opérations de développement qui ne peuvent pas être réalisées par GitHub seul : builds locaux, tests, services et, dans les jalons suivants, matériel, série, flash et appareils Android.

## Frontière de sécurité

devMcp n’expose pas de shell arbitraire. Une action est déclarée côté serveur pour un projet donné, avec un exécutable et des arguments structurés. Le modèle choisit une action autorisée ; il ne fournit jamais une ligne de commande libre.

Le cœur J01 impose notamment :

- un workspace réel et borné par projet ;
- des actions nommées ;
- un exécutable absolu ;
- le refus des shells et lanceurs génériques ;
- aucun paramètre libre injecté dans argv en J01 ;
- un timeout serveur ;
- une limite de sortie ;
- un audit JSONL sans stdout/stderr ni secret ;
- un transport MCP STDIO basé sur le SDK PHP officiel.

## Installation

Prérequis : PHP 8.3+, Composer.

1. Installer les dépendances avec Composer.
2. Copier config/global.example.php vers config/global.php et adapter les chemins locaux.
3. Lancer bin/devmcp.

En production, le fichier de configuration doit être hors du dépôt et protégé par le système. La variable DEVMCP_CONFIG permet d’indiquer son chemin, par exemple /etc/devmcp/global.php.

## Outils MCP J01

- project_list
- project_describe
- action_list
- action_describe
- action_run
- audit_tail

Les opérations longues asynchrones, le transport Streamable HTTP, les agents distants, Android/ADB, le flash, la série et les services arrivent dans les jalons suivants.

Voir docs/CONCEPTION.md, docs/DECISIONS.md et docs/AVANCEMENT.md.
