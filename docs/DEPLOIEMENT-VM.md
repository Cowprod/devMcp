# Déploiement VM

## Mode recommandé : Secure MCP Tunnel + STDIO

Pour ChatGPT, le mode recommandé n'expose pas devMcp sur Internet.

Architecture :

ChatGPT
→ Secure MCP Tunnel OpenAI
→ tunnel-client sur la VM
→ processus STDIO bin/devmcp
→ file persistante /var/lib/devmcp/jobs
→ service devmcp-worker
→ workspaces /srv/devmcp-workspaces

Le tunnel est sortant depuis la VM. Le serveur MCP peut rester totalement privé.

Documentation officielle :
- https://developers.openai.com/api/docs/guides/secure-mcp-tunnels
- https://github.com/openai/tunnel-client

Le tunnel-client prend en charge un binding MCP STDIO. C'est le chemin privilégié pour devMcp : il évite d'ajouter une surface TCP locale inutile et conserve le transport STDIO déjà qualifié.

## Arborescence VM cible

- /opt/devmcp : checkout du serveur devMcp
- /etc/devmcp/global.php : configuration locale non versionnée
- /var/lib/devmcp/jobs : métadonnées et sorties de jobs
- /var/lib/devmcp/sessions : sessions MCP HTTP si le mode HTTP est utilisé
- /var/log/devmcp/audit.jsonl : audit
- /srv/devmcp-workspaces : checkouts des projets pilotés

Utilisateur système recommandé : devmcp, non privilégié.

Le worker n'obtient pas sudo. Les opérations nécessitant un privilège système doivent passer par un mécanisme dédié et explicitement borné, jamais par une capacité shell générique.

## Services

### Worker

Le template deploy/systemd/devmcp-worker.service exécute la file persistante de jobs.

Il dispose en écriture uniquement de :
- /var/lib/devmcp
- /var/log/devmcp
- /srv/devmcp-workspaces

### Endpoint HTTP local facultatif

public/index.php fournit également :
- POST/DELETE/OPTIONS /mcp via Streamable HTTP ;
- GET /healthz.

Le template deploy/systemd/devmcp-http.service écoute uniquement sur 127.0.0.1:8765.

Ce mode est utile pour l'inspecteur MCP ou pour un binding tunnel HTTP. Il n'est pas nécessaire si tunnel-client utilise directement bin/devmcp en STDIO.

## Diagnostic VM

bin/devmcp-doctor vérifie sans exécuter d'action :

- PHP >= 8.3 ;
- présence et validité de la configuration ;
- registre de projets ;
- répertoires audit/jobs/sessions accessibles en écriture ;
- workspaces ;
- exécutables absolus de toutes les actions ;
- cwd bornés et existants.

La sortie est JSON et le code retour vaut 0 uniquement si tous les contrôles passent.

## Création du profil Secure MCP Tunnel

Pages officielles utiles :

- Tunnels : https://platform.openai.com/settings/organization/tunnels
- Runtime API keys : https://platform.openai.com/settings/organization/api-keys
- Admin API keys : https://platform.openai.com/settings/organization/admin-keys
- ChatGPT connectors : https://chatgpt.com/#settings/Connectors

La documentation OpenAI recommande de commencer par :

tunnel-client help quickstart

Pour un binding STDIO local, le flux officiel est de créer un profil avec un tunnel ID et une commande MCP, puis de lancer doctor et run. Pour devMcp, la commande MCP du profil est :

/usr/bin/php /opt/devmcp/bin/devmcp

Exemple conceptuel conforme au quickstart officiel :

tunnel-client init --sample sample_mcp_stdio_local --profile devmcp --tunnel-id <TUNNEL_ID> --mcp-command "/usr/bin/php /opt/devmcp/bin/devmcp"
tunnel-client doctor --profile devmcp --explain
tunnel-client run --profile devmcp

Le daemon doit recevoir :
- CONTROL_PLANE_TUNNEL_ID ;
- CONTROL_PLANE_API_KEY, créée dans Runtime API keys.

Ne jamais placer OPENAI_ADMIN_KEY dans le service long-lived. Cette clé ne sert qu'à la gestion administrative des tunnels.

Permissions runtime requises : Tunnels Read + Use.

Limite importante du binding STDIO : une seule instance tunnel-client active par tunnel ID. Deux processus concurrents peuvent lancer deux enfants MCP différents et casser l'affinité d'initialisation.

Pour une supervision long-lived gérée par tunnel-client, la documentation OpenAI recommande aussi le mécanisme runtimes connect/status plutôt qu'un nohup/disown artisanal.

## Configuration ChatGPT

Dans ChatGPT :
- créer une application MCP personnalisée ;
- Connection : Tunnel ;
- sélectionner le tunnel du workspace ;
- analyser les outils ;
- conserver les permissions d'écriture selon la politique du workspace.

Aucun port entrant vers la VM n'est requis par ce mode.

## Mode HTTP

Le SDK MCP PHP officiel nécessite un stockage de sessions persistant pour les sessions HTTP stateful. devMcp utilise FileSessionStore.

Les jobs sont eux aussi persistants sur disque et exécutés par devmcp-worker : un appel HTTP qui lance un job peut donc être suivi par un autre processus PHP ou après un redémarrage du frontend.

## Checklist avant connexion ChatGPT

1. PHP 8.3+ et Composer disponibles.
2. /opt/devmcp installé et composer install exécuté.
3. /etc/devmcp/global.php créé depuis deploy/config/global.vm.example.php.
4. Workspaces utiles présents sous /srv/devmcp-workspaces.
5. devmcp-worker actif.
6. bin/devmcp-doctor retourne ok=true.
7. bin/devmcp démarre sans erreur avec DEVMCP_CONFIG.
8. tunnel-client doctor est vert.
9. tunnel-client runtime actif.
10. outil project_list visible depuis ChatGPT.
11. premier action_run en lecture validé avant les actions mutantes.
