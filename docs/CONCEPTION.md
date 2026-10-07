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
→ runner
→ jobs / artefacts / devices / services dans les jalons suivants
→ audit

## Modèle de sécurité

Le fichier de configuration est une frontière de confiance. En production il est placé hors du dépôt, par exemple dans /etc/devmcp/global.php, et protégé par les droits Unix.

Une action J01 contient un argv statique. Aucun argument fourni par le modèle n'est injecté dans la commande.

Contraintes J01 :

- projet choisi parmi le registre ;
- action choisie parmi l'allowlist du projet ;
- workspace résolu par realpath ;
- cwd obligatoirement interne au workspace ;
- exécutable absolu ;
- shells et lanceurs génériques refusés ;
- Symfony Process avec argv tableau ;
- timeout plafonné globalement ;
- stdout/stderr tronqués ;
- audit sans sortie de commande.

## Multi-machines

La cible reste un gateway non privilégié et des runners spécialisés. J01 implémente le cœur mono-processus/STDIO pour valider le modèle de capacités. La séparation gateway/runner et le transport HTTP sécurisé sont des jalons ultérieurs.

## Git

Les opérations Git distantes sont laissées au connecteur GitHub natif lorsque possible.

devMcp ne doit conserver que les opérations Git locales indispensables au workspace d'exécution : état, synchronisation contrôlée, checkout borné, etc. Elles seront ajoutées comme actions ou adapters explicites, jamais via une commande libre.

## Jobs longs

Builds, tests E2E, flash et campagnes physiques devront être asynchrones :

action_start → job_id
job_status / job_output / job_cancel
artifact_list / artifact_get

J01 reste volontairement synchrone pour les actions courtes afin de figer d'abord la frontière de sécurité.
