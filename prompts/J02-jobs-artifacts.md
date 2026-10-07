# Mandat J02 — jobs asynchrones et artefacts

Tu arrives sur ce projet sans contexte antérieur. Git est la source de vérité.

Branche de départ : main au merge J01
Branche de travail : feat/j02-jobs-artifacts

## Objectif

Permettre à devMcp de démarrer des opérations longues sans bloquer l’appel MCP, suivre leur état et leurs sorties, les annuler, et récupérer des artefacts déclarés.

## Contraintes

- aucune régression de la frontière de sécurité J01 ;
- aucune commande libre ;
- job_id non prédictible ;
- stdout/stderr lisibles par morceaux bornés ;
- artefacts déclarés côté serveur uniquement ;
- aucun chemin libre fourni par le client ;
- résolution réelle du fichier avant lecture ;
- symlink hors workspace rejeté ;
- aucun transport HTTP ni runner distant dans J02.

## Outils attendus

- action_start
- job_status
- job_output
- job_cancel
- artifact_list
- artifact_get

## Tests obligatoires

- lancement asynchrone ;
- transition vers succès ;
- annulation ;
- audit de début/fin ;
- lecture d’un artefact déclaré ;
- SHA-256 ;
- lecture par chunk ;
- rejet d’un symlink hors workspace ;
- construction du serveur MCP avec les nouveaux outils.

## STOP

STOP si l’implémentation nécessite un shell arbitraire, un chemin d’artefact libre venant du client ou une modification des décisions de sécurité validées.
