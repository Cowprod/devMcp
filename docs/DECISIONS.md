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
Version J01/J02 : mcp/sdk 0.8.1.
Conséquences : transport STDIO actuel ; Streamable HTTP prévu ensuite.

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
Statut : VALIDÉE
Décision : J02 conserve les jobs en mémoire dans le processus MCP STDIO.
Conséquences : les jobs ne survivent pas au redémarrage ; la persistance appartient au futur modèle gateway/runner.
