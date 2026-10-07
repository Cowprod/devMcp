# Mandat J01 — cœur sécurisé

Tu arrives sur ce projet sans contexte antérieur. Git est la source de vérité.

Branche de départ : main
Branche de travail : feat/j01-core

## Sources à lire

- docs/CONCEPTION.md
- docs/DECISIONS.md
- docs/QUESTIONS-OUVERTES.md
- docs/QUALIFICATION-TECHNIQUE.md
- docs/REFERENTIELS.md

## Objectif

Implémenter un premier serveur MCP PHP utilisable en STDIO avec registre de projets, liste d'actions, exécution synchrone d'actions courtes et audit.

## Contraintes

- aucun shell arbitraire ;
- aucune ligne de commande fournie par le client MCP ;
- exécutable absolu ;
- cwd borné au workspace ;
- timeout et taille de sortie plafonnés ;
- audit sans stdout/stderr ;
- aucune implémentation anticipée de J02+.

## Tests obligatoires

- chargement d'un projet valide ;
- rejet d'un shell ;
- rejet d'un exécutable relatif ;
- exécution argv directe ;
- audit sans contenu stdout/stderr.

## STOP

STOP en cas de nécessité d'ouvrir un shell générique, de modifier une décision VALIDÉE, ou d'introduire un argument libre non borné.
