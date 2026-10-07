# Décisions

## D-001
Type : TECHNIQUE
Statut : VALIDÉE
Décision : devMcp est un serveur MCP générique écrit en PHP.
Conséquences : le cœur n'est pas spécifique à multicam, touchDeck ou un autre projet.

## D-002
Type : SÉCURITÉ
Statut : VALIDÉE
Décision : aucun shell arbitraire n'est exposé.
Conséquences : le modèle sélectionne des actions nommées ; il ne fournit pas de ligne de commande.

## D-003
Type : SÉCURITÉ
Statut : VALIDÉE
Décision : les droits sont bornés par projet.
Conséquences : workspace, actions, périphériques et services sont déclarés côté serveur.

## D-004
Type : TECHNIQUE
Statut : VALIDÉE
Décision : les commandes sont exécutées sous forme d'argv structurés.
Conséquences : aucune concaténation de shell ; Symfony Process reçoit un tableau d'arguments.

## D-005
Type : TECHNIQUE
Statut : VALIDÉE
Décision : le SDK MCP PHP officiel est utilisé.
Version actuelle : mcp/sdk 0.8.1.
Conséquences : STDIO et Streamable HTTP utilisent la même registry d'outils.

## D-006
Type : ARCHITECTURE
Statut : VALIDÉE
Décision : GitHub natif est le plan de contrôle du code ; devMcp est le plan d'exécution local.
Conséquences : ne pas reconstruire inutilement les fonctions GitHub déjà disponibles.

## D-007
Type : SÉCURITÉ
Statut : VALIDÉE
Décision : la configuration de production est hors dépôt et protégée par le système.
Conséquences : config/global.php est ignoré ; DEVMCP_CONFIG peut pointer vers /etc/devmcp/global.php.

## D-008
Type : PÉRIMÈTRE
Statut : VALIDÉE
Décision : le périmètre initial concerne les dépôts Cowprod/devMcp, multicam, touchDeck, archiveCowprod, aiDevMethod et referenciel.
Conséquences : l'activation réelle d'un projet dépend de son manifest local et de son workspace présent sur la machine.

## D-009
Type : TECHNIQUE
Statut : VALIDÉE
Décision : les opérations longues sont des jobs asynchrones.
Conséquences : action_start rend immédiatement un job_id ; suivi, sortie et annulation sont des appels séparés.

## D-010
Type : SÉCURITÉ
Statut : VALIDÉE
Décision : un artefact doit être déclaré statiquement dans l’action.
Conséquences : aucun chemin de fichier libre n’est accepté par artifact_get.

## D-011
Type : SÉCURITÉ
Statut : VALIDÉE
Décision : la lecture d’un artefact est bornée au workspace après résolution realpath.
Conséquences : les symlinks pointant hors workspace sont rejetés.

## D-012
Type : TECHNIQUE
Statut : VALIDÉE
Décision : les gros fichiers sont lus par morceaux base64.
Conséquences : artifact_get accepte offset/length avec une taille maximale configurable.

## D-013
Type : TECHNIQUE
Statut : REMPLACÉE PAR D-014
Décision historique J02 : conserver les jobs uniquement en mémoire du processus MCP STDIO.
Motif du remplacement : incompatible avec plusieurs requêtes HTTP et insuffisant pour un service distant fiable.

## D-014
Type : ARCHITECTURE
Statut : VALIDÉE
Décision : les jobs sont persistés sur disque et exécutés par un worker séparé.
Conséquences : frontend MCP, worker et appels successifs peuvent être des processus distincts ; les sorties et métadonnées survivent à un redémarrage du frontend.

## D-015
Type : SÉCURITÉ
Statut : VALIDÉE
Décision : pour ChatGPT, le chemin de déploiement privilégié est Secure MCP Tunnel avec binding STDIO.
Conséquences : aucun port MCP public n'est requis ; le tunnel-client reste dans la VM et effectue uniquement des connexions sortantes.

## D-016
Type : TECHNIQUE
Statut : VALIDÉE
Décision : devMcp fournit aussi un endpoint Streamable HTTP local utilisant FileSessionStore.
Conséquences : HTTP peut servir à MCP Inspector ou à un binding tunnel HTTP ; le template écoute uniquement sur 127.0.0.1.

## D-017
Type : SÉCURITÉ
Statut : VALIDÉE
Décision : ne pas inventer une authentification statique propriétaire pour ChatGPT.
Conséquences : en mode tunnel, l'accès repose sur le contrôle OpenAI du tunnel ; un déploiement public futur devra utiliser OAuth 2.1 conforme MCP plutôt qu'une API key ad hoc.

## D-018
Type : ARCHITECTURE
Statut : VALIDÉE
Décision : chaque action déclare un target logique de runner.
Conséquences : action_start persiste le target ; un worker ne réclame que les jobs correspondant à DEVMCP_TARGET. La valeur par défaut est local.

## D-019
Type : SÉCURITÉ
Statut : VALIDÉE
Décision : deux jobs d'un même projet ne modifient jamais simultanément le même workspace.
Conséquences : le worker tient un verrou exclusif par projet pendant toute l'exécution de l'action.

## D-020
Type : SÉCURITÉ
Statut : VALIDÉE
Décision : action_run synchrone est interdit par défaut.
Conséquences : une action doit déclarer sync=true et cibler local pour être exécutable hors worker. Les actions de build/test/mutation passent par action_start.
