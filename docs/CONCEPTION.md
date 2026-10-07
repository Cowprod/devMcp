# Conception

## Objectif

devMcp fournit à ChatGPT un plan d'exécution local borné pour les projets Cowprod. GitHub reste le plan de contrôle du code ; devMcp prend en charge les opérations qui nécessitent une machine réelle : build, test, service et, à terme, matériel.

Le système doit permettre une boucle autonome sans transformer ChatGPT en utilisateur shell distant.

## Architecture cible

ChatGPT
→ GitHub natif : lecture/écriture du dépôt, branches, PR, issues, CI
→ devMcp : capacités locales explicitement autorisées

devMcp
→ registre de projets
→ politique de sécurité
→ dispatcher d'actions
→ runner synchrone court
→ gestionnaire de jobs asynchrones
→ artefacts déclarés
→ devices / services / runners distants dans les jalons suivants
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
- timeout plafonné globalement ;
- sorties renvoyées par morceaux bornés ;
- artefacts explicitement déclarés dans l’action ;
- résolution realpath de l’artefact au moment de la lecture ;
- rejet d’un artefact symlinké hors workspace ;
- audit sans contenu stdout/stderr.

## Jobs asynchrones

Une action longue est démarrée par action_start.

Flux :

action_start(project, action)
→ job_id
→ job_status(job_id)
→ job_output(job_id, offsets)
→ artifact_list(job_id)
→ artifact_get(job_id, artifact, offset, length)

États J02 :

running
→ succeeded | failed | cancelled | timed_out

Le job_id est un identifiant aléatoire de 128 bits représenté en hexadécimal.

Le processus reste détenu par l’instance MCP STDIO courante. Un redémarrage du serveur invalide donc les jobs en mémoire. La persistance inter-processus sera traitée avec le modèle gateway/runner.

## Sorties

job_output expose stdout et stderr séparément avec :

- offset demandé ;
- next_offset ;
- indicateur eof ;
- contenu borné par max_output_bytes.

Le client peut donc lire progressivement un log sans demander une réponse MCP gigantesque.

## Artefacts

Une action peut déclarer des artefacts :

- id stable ;
- chemin relatif au workspace ;
- type MIME.

artifact_list retourne présence, taille et SHA-256.

artifact_get retourne un morceau base64 avec offset, next_offset et eof. Le volume maximal par appel est borné par max_artifact_chunk_bytes.

Ce modèle permet de manipuler aussi bien un rapport JSON qu’un APK sans exposer de chemin arbitraire au modèle.

## Multi-machines

La cible reste un gateway non privilégié et des runners spécialisés. J01/J02 implémentent le cœur mono-processus/STDIO. La séparation gateway/runner et le transport HTTP sécurisé sont des jalons ultérieurs.

## Git

Les opérations Git distantes sont laissées au connecteur GitHub natif lorsque possible.

devMcp ne doit conserver que les opérations Git locales indispensables au workspace d'exécution : état, synchronisation contrôlée, checkout borné, etc. Elles sont ajoutées comme actions ou adapters explicites, jamais via une commande libre.
