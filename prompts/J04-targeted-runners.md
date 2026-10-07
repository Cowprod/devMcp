# Mandat J04 — targets de runners et isolation de workspace

Git est la source de vérité.

Branche : feat/j04-targeted-runners

## Objectif

Permettre à plusieurs pools de workers d'exister sans qu'un worker puisse exécuter une action destinée à un autre environnement, et empêcher les exécutions concurrentes sur un même workspace.

## Contraintes

- target déclaré côté serveur dans l'action ;
- target persisté dans le job ;
- worker lié à un target par configuration locale ;
- aucun target fourni librement par le client lors de action_start ;
- revalidation target job/manifest au moment de l'exécution ;
- verrou exclusif par projet pendant toute action worker ;
- action_run synchrone interdit par défaut ;
- seule une action explicitement sync=true et target=local peut utiliser action_run ;
- aucune implémentation NFS ou protocole runner distant implicite.

## Tests

- job android-lab non réclamé par worker local ;
- job android-lab réclamé par worker android-lab ;
- action_run sans sync=true rejeté ;
- action_run target non-local rejeté ;
- régression jobs, artefacts, HTTP et STDIO.

## STOP

STOP si la solution nécessite que le client choisisse un exécutable, un target non déclaré ou une commande distante libre.
