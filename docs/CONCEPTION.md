# Conception

## Objectif

devMcp fournit à ChatGPT un plan d'exécution borné pour les projets Cowprod. GitHub reste le plan de contrôle du code ; devMcp prend en charge les opérations qui nécessitent une machine réelle : build, test, service et, à terme, matériel.

Le système doit permettre une boucle autonome sans transformer ChatGPT en utilisateur shell distant.

## Architecture cible

ChatGPT
→ GitHub natif : dépôt, branches, PR, issues, CI
→ Secure MCP Tunnel : accès privé au serveur devMcp
→ devMcp : capacités locales explicitement autorisées

devMcp
→ registre de projets
→ politique de sécurité
→ outils MCP
→ runner synchrone pour actions courtes
→ file persistante de jobs
→ worker séparé pour actions longues
→ artefacts déclarés
→ audit

## Modèle de sécurité

Le fichier de configuration est une frontière de confiance. En production il est placé hors du dépôt, par exemple dans /etc/devmcp/global.php, et protégé par les droits Unix.

Une action contient un argv statique. Aucun argument fourni par le modèle n'est injecté dans la commande.

Contraintes :

- projet choisi parmi le registre ;
- action choisie parmi l'allowlist du projet ;
- workspace résolu par realpath ;
- cwd obligatoirement interne au workspace ;
- exécutable absolu ;
- shells et lanceurs génériques refusés ;
- Symfony Process avec argv tableau ;
- timeout appliqué par le worker ;
- sorties persistantes et plafonnées ;
- artefacts explicitement déclarés dans l’action ;
- résolution realpath de l’artefact au moment de la lecture ;
- rejet d’un artefact symlinké hors workspace ;
- audit sans contenu stdout/stderr ;
- aucun secret renvoyé au modèle.

## Jobs asynchrones persistants

Une action longue est démarrée par action_start.

Flux :

action_start(project, action)
→ job_id + état queued
→ worker claim
→ état running
→ job_status / job_output
→ succeeded | failed | cancelled | timed_out
→ artifact_list / artifact_get

Le job_id est un identifiant aléatoire de 128 bits représenté en hexadécimal.

Chaque job possède un dossier persistant sous jobs.directory :

- job.json : métadonnées ;
- stdout.log ;
- stderr.log ;
- verrou local.

La file utilise un verrou global uniquement pendant le claim. Un worker peut donc être séparé du frontend MCP et les requêtes successives peuvent provenir de processus PHP distincts.

## Worker

devmcp-worker :

1. réclame atomiquement un job queued ;
2. résout projet/action depuis la configuration locale ;
3. démarre directement l'argv autorisé ;
4. draine stdout/stderr pendant l'exécution ;
5. vide régulièrement les buffers Symfony Process afin d'éviter l'accumulation mémoire ;
6. applique timeout et demande d'annulation ;
7. persiste le résultat final ;
8. audite la transition.

Le worker n'exécute jamais une commande enregistrée dans job.json. Le job persiste uniquement project + action ; l'argv réel est rechargé depuis la configuration de confiance au moment de l'exécution.

Cette propriété empêche de transformer la file de jobs en canal d'injection de commandes.

## Sorties

Les logs persistants sont plafonnés par max_job_log_bytes.

job_output expose stdout et stderr séparément avec :

- offset demandé ;
- next_offset ;
- indicateur eof ;
- indicateur truncated ;
- contenu borné par max_output_bytes.

## Artefacts

Une action peut déclarer des artefacts :

- id stable ;
- chemin relatif au workspace ;
- type MIME.

artifact_list retourne présence, taille et SHA-256.

artifact_get retourne un morceau base64 avec offset, next_offset et eof. Le volume maximal par appel est borné par max_artifact_chunk_bytes.

## Transport ChatGPT

Le chemin recommandé est :

ChatGPT
→ endpoint OpenAI du Secure MCP Tunnel
→ tunnel-client dans la VM
→ binding STDIO
→ bin/devmcp

Le serveur MCP n'a alors aucun socket entrant public.

Le tunnel-client dispose uniquement d'une Runtime API key avec les permissions nécessaires au tunnel. Une clé d'administration OpenAI n'est pas installée dans le daemon.

## Streamable HTTP

devMcp expose également /mcp via le Streamable HTTP du SDK officiel.

Ce transport utilise :

- FileSessionStore pour les révisions MCP qui utilisent encore les sessions ;
- la limite de corps HTTP du SDK ;
- la pile middleware sécurisée par défaut du SDK, notamment la protection DNS rebinding et CORS.

Le template systemd écoute sur 127.0.0.1 uniquement.

Ce mode est prévu pour l'inspecteur MCP, les tests, ou un binding tunnel HTTP. Il n'est pas nécessaire au chemin ChatGPT recommandé.

## Multi-machines

La première VM peut être à la fois gateway logique et runner local. L'architecture garde néanmoins la frontière entre frontend MCP et worker.

Le jalon suivant étendra cette frontière à plusieurs runners/targets sans donner au modèle des coordonnées SSH ni une commande distante libre.

## Git

Les opérations Git distantes sont laissées au connecteur GitHub natif lorsque possible.

devMcp ne conserve que les opérations Git locales indispensables au workspace d'exécution : état, synchronisation contrôlée, checkout borné, etc. Elles sont ajoutées comme actions ou adapters explicites, jamais via une commande libre.
