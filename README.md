# devMcp

MCP PHP générique servant de plan d’exécution contrôlé pour les projets Cowprod.

Le but est de permettre à ChatGPT de piloter les opérations de développement qui ne peuvent pas être réalisées par GitHub seul : builds locaux, tests, services et, dans les jalons suivants, matériel, série, flash et appareils Android.

## Frontière de sécurité

devMcp n’expose pas de shell arbitraire. Une action est déclarée côté serveur pour un projet donné, avec un exécutable et des arguments structurés. Le modèle choisit une action autorisée ; il ne fournit jamais une ligne de commande libre.

Le cœur impose notamment :

- un workspace réel et borné par projet ;
- des actions nommées ;
- un exécutable absolu ;
- le refus des shells et lanceurs génériques ;
- aucun paramètre libre injecté dans argv ;
- un timeout serveur ;
- des lectures de sortie bornées ;
- des artefacts explicitement déclarés par action ;
- un audit JSONL sans stdout/stderr ni secret ;
- des jobs persistants exécutés par un worker séparé.

## Transports

### ChatGPT : Secure MCP Tunnel + STDIO

C'est le chemin recommandé.

ChatGPT → Secure MCP Tunnel → tunnel-client sur la VM → bin/devmcp.

Le serveur MCP n'a pas besoin d'être exposé sur Internet.

### Streamable HTTP local

public/index.php expose :

- /mcp : Streamable HTTP MCP ;
- /healthz : santé minimale.

Le template systemd écoute uniquement sur 127.0.0.1:8765. HTTP utilise FileSessionStore conformément aux exigences du SDK MCP PHP.

## Installation

Prérequis : PHP 8.3+, Composer.

1. Installer les dépendances avec Composer.
2. Copier config/global.example.php vers config/global.php et adapter les chemins locaux.
3. Démarrer bin/devmcp pour STDIO et bin/devmcp-worker pour les jobs.

En VM, la configuration doit rester hors du dépôt, typiquement /etc/devmcp/global.php.

Voir docs/DEPLOIEMENT-VM.md.

## Outils MCP

### Projets et actions

- project_list
- project_describe
- action_list
- action_describe
- action_run

### Jobs asynchrones

- action_start
- job_status
- job_output
- job_cancel

### Artefacts

- artifact_list
- artifact_get

artifact_get lit les fichiers par morceaux base64 bornés. Un artefact doit être déclaré à l’avance dans l’action et rester physiquement dans le workspace du projet.

### Audit

- audit_tail

Voir docs/CONCEPTION.md, docs/DECISIONS.md et docs/AVANCEMENT.md.
