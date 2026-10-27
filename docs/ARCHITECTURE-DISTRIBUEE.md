# Architecture distribuée de devMcp — proposition de conception

Statut : PROPOSITION TECHNIQUE — à valider avant déploiement.
Périmètre : serveur central générique et agents PHP légers. Aucun couplage avec un projet utilisateur particulier.

## Contraintes validées

- Le cœur de devMcp reste indépendant de tout projet (TouchDeck est un projet ordinaire).
- PHP est le langage du serveur et des agents ; Go exclu.
- MySQL/MariaDB est retenu pour le registre et l'ordonnancement central.
- Un seul serveur MCP et un seul Secure MCP Tunnel, sur le central.
- Les agents ne nécessitent ni serveur MCP, ni serveur Web, ni connexion MySQL.
- ZeroTier fournit le réseau privé, mais ne constitue pas à lui seul une authentification.
- Aucune commande shell arbitraire n'est exposée à ChatGPT.
- Les fonctionnalités locales existantes restent opérationnelles durant la migration.

## Composants

1. **Central** : API MCP existante, administration Web, registre, politique, planificateur, API des agents, audit.
2. **Core PHP partagé** : ActionDefinition, validation typée, résolution des workspaces, garde-fous sur les chemins, exécution argv sans shell, limites de durée et de sortie.
3. **Agent PHP CLI** : service sortant HTTPS, synchronisation des politiques autorisées, exécution locale, journal et état local minimal, transfert des résultats.
4. **Base centrale MySQL/MariaDB** : source de vérité des machines, projets, implantations, politiques, jobs, événements et audit.
5. **Stockage de fichiers** : sorties volumineuses et artefacts, accessibles par identifiants contrôlés ; pas de BLOB volumineux obligatoire dans MySQL.

## Modèle de données logique

- machine : identifiant stable, nom, OS, architecture, statut, dernière présence, version agent.
- agent_identity : machine, identité, empreinte, date d'expiration, révocation.
- project : identifiant fonctionnel, dépôt attendu, description ; aucune machine imposée.
- deployment : association project/machine, chemin workspace canonique sur la machine, état, capacités.
- action_template : identifiant, version, schéma des paramètres, capacité requise, niveau de risque.
- deployment_action : activation explicite d'un modèle d'action pour une implantation.
- policy_revision : version et empreinte de la politique approuvée, date d'activation.
- job : identifiant, implantation, action, paramètres typés, révision de politique, état, échéance, clé d'idempotence.
- job_attempt : tentative, agent, bail, jeton de fencing, début/fin, code retour.
- job_event : transitions horodatées UTC, acteur, motif.
- artifact : déclaration, chemin logique, taille, SHA-256, disponibilité.
- audit_event : acteur, origine, action, objet, résultat, horodatage UTC.

Les clés étrangères, contraintes d'unicité, index des jobs en attente et des événements seront définis dans les migrations versionnées. Les secrets ne doivent pas être enregistrés en clair dans la base.

## Protocole agent sortant (v1)

- HTTPS sur interface ZeroTier, authentification propre à chaque agent ; TLS obligatoire.
- Enrôlement initial via jeton à usage unique et durée courte, suivi de l'émission d'une identité révocable.
- Heartbeat avec version, capacités, état et dernière politique connue.
- Claim de jobs éligibles ; réponse contenant **identifiants d'action et paramètres typés**, jamais une commande libre.
- Résolution locale de l'action à partir d'une politique de confiance versionnée ; refus si inconnue, périmée ou incompatible.
- Accusé de réception, renouvellement du bail, événements de progression, sorties bornées, résultat final.
- Annulation coopérative et timeout ; une déconnexion ne signifie pas nécessairement que le processus a cessé.
- Jobs mutateurs non rejoués automatiquement après une perte de confirmation : état « à réconcilier ».
- Horodatage UTC, identifiants aléatoires, versions de protocole explicites.

### Invariant de sécurité

Un agent ne doit **jamais** exécuter un argv reçu d'un job ou d'un appel MCP. Comme le worker actuel, il reconstruit la commande depuis sa politique locale de confiance. Les chemins de workspace et les paramètres sont vérifiés au moment de l'exécution.

Les modifications de politique par l'administration Web doivent rester séparées des capacités accordées au modèle. Les actions à effet sensible (flash, suppression, déploiement) nécessitent des autorisations dédiées.

## Administration Web

Interface PHP 8.3 + Bootstrap 5, authentifiée, protégée contre CSRF et limitée au réseau d'administration :

- Tableau de bord : agents connectés, jobs, erreurs, versions.
- Machines : découverte/enrôlement, révocation, capacités, mise à jour, diagnostic.
- Projets : dépôt, description, implantations, chemins et état de synchronisation.
- Actions : catalogue, paramètres, permissions par implantation, aperçu de la politique effective.
- Jobs : file, sorties, artefacts, annulation, réconciliation des exécutions incertaines.
- Audit : recherche par machine, projet, action, acteur et période.

## Installation et mises à jour

SSH (clé labCowprod) est réservé à l'installation initiale, au secours et aux interventions administratives. Ne jamais distribuer la clé privée aux agents. Vérifier l'identité SSH de chaque machine à l'enrôlement.

Agent installé dans un répertoire versionné, exécuté sous un compte non privilégié ; mise à jour téléchargée et vérifiée (signature ou empreinte approuvée), remplacement atomique, contrôle de santé, retour arrière. Le mécanisme privilégié de mise à jour est distinct du processus agent. Déploiement progressif par machine pilote.

Linux/systemd en premier ; Windows et service natif ultérieurement, sans modifier le protocole central.

## Migration sans interruption

M0 : tests de non-régression sur la voie locale existante (FileJobStore, JobWorker, Secure MCP Tunnel).
M1 : extraction du Core partagé, sans changement de comportement.
M2 : schéma MySQL, migrations et registre en lecture parallèle ; aucune bascule implicite.
M3 : administration Web et import contrôlé des projets actuels.
M4 : API agent et agent PHP sur machine de test distincte ; aucune action matérielle.
M5 : pilote avec jobs de lecture, puis build/test, puis actions mutatrices explicitement autorisées.
M6 : coexistence durable local/distant et migration progressive de l'ordonnancement.
M7 : distribution des mises à jour, diagnostics et support Windows.

## Tests d'acceptation

- Aucun nom de projet particulier dans le Core.
- Projet présent sur deux machines, droits et workspaces indépendants.
- Projet absent d'une machine : aucun accès possible à son workspace.
- Agent hors ligne : job en attente ou expiré, jamais perdu silencieusement.
- Redémarrage central ou agent : pas de double exécution d'une action mutatrice.
- Révocation d'identité : impossibilité de récupérer de nouveaux jobs.
- Tentatives de traversal, symlink hors workspace, paramètres inconnus, exécutable libre : rejet.
- Révision de politique incohérente : refus de l'exécution.
- Pas de régression des 26 actions existantes du projet TouchDeck.
- Aucun flash ni installation OTA pendant la qualification d'architecture.

## Points à vérifier avant implémentation

1. Disponibilité et version du serveur MySQL/MariaDB central ; stratégie de sauvegarde.
2. Versions PHP CLI disponibles sur les machines pilotes ; dépendances minimales réellement nécessaires.
3. Terminaison TLS sur ZeroTier et gestion pratique des identités agents.
4. Granularité des autorisations et confirmation des actions à risque.
5. Politique de conservation des logs et artefacts.
6. Séparation des permissions administrateur Web et des outils MCP.

Cette proposition n'autorise ni déploiement automatique ni modification de la configuration de production.
