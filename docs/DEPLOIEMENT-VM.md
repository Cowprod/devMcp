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

## Création du profil tunnel

Une fois le tunnel_id et une Runtime API key disponibles, utiliser le quickstart du tunnel-client comme source de vérité.

Le binding attendu est conceptuellement :

tunnel-client
  main → stdio → /usr/bin/php /opt/devmcp/bin/devmcp

Ne jamais placer une Admin API key dans le service long-lived. Le daemon de tunnel n'a besoin que d'une Runtime API key disposant de Tunnels Read + Use.

## Configuration ChatGPT

Dans ChatGPT :
- créer une application MCP personnalisée ;
- Connection : Tunnel ;
- sélectionner le tunnel du workspace ;
- analyser les outils ;
- conserver les permissions d'écriture selon la politique du workspace.

Aucun port entrant vers la VM n'est requis par ce mode.

## Mode HTTP

Le SDK MCP PHP officiel nécessite un stockage de sessions persistant en HTTP. devMcp utilise FileSessionStore.

Les jobs sont eux aussi persistants sur disque et exécutés par devmcp-worker : un appel HTTP qui lance un job peut donc être suivi par un autre processus PHP ou après un redémarrage du frontend.

## Checklist avant connexion ChatGPT

1. PHP 8.3+ et Composer disponibles.
2. /opt/devmcp installé et composer install exécuté.
3. /etc/devmcp/global.php créé depuis deploy/config/global.vm.example.php.
4. Workspaces utiles présents sous /srv/devmcp-workspaces.
5. devmcp-worker actif.
6. bin/devmcp démarre sans erreur avec DEVMCP_CONFIG.
7. tunnel-client doctor est vert.
8. tunnel-client runtime actif.
9. outil project_list visible depuis ChatGPT.
10. premier action_run en lecture validé avant les actions mutantes.
