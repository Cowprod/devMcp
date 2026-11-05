# Hébergement distant sur `case` — contrat de publication (phase 2A)

Statut au 5 novembre 2026 : **validation de plan uniquement, sans exécution**.

## Objectif

Préparer trois projets HTTP distincts (`static`, `php`, `node`/WebSocket)
sans modifier les routes, interfaces WAN/LAN, NAT, pare-feu ou services de
`caseRouter`.

Le code `RemoteHostingReleasePlan::preview()` est un validateur pur,
sans accès disque, réseau, Git ni exécution. Il est volontairement **non
raccordé à la file des actions distantes** actuellement limitée à la
lecture seule ; sa présence dans le dépôt ne confère donc aucun droit.

## Contrat d'entrée

Le **fichier local administrateur** contient exactement :

- `version=1` et `enabled=true` : activation opt-in ;
- `machine_id` : identité précise de l'agent (32 caractères hex) ;
- `workspace_root=/srv/devmcp-hosting` : seul répertoire autorisé ;
- `bind_ipv4` : adresse IPv4 spécifiée par l'administrateur, à vérifier
  ensuite comme réellement portée par l'interface ZeroTier ;
- `deployments` : jusqu'à 32 entrées, indexées par `deployment_id`
  hexadécimal, associant `project_id`, `repository`, `runtime`,
  `port`, `minimum_revision` et `enabled`.

Chaque déploiement pointe sur un dépôt de l'espace `Cowprod`,
un runtime fixe parmi `static`, `php` et `node` et un port TCP unique
entre 12000 et 19999. Aucun conflit de nom de projet ou de port n'est
accepté dans une politique locale.

Le message central peut uniquement désigner
`deployment_id`, `machine_id`, `project_id` et `revision`
(40 caractères hexadécimaux). Toute autre clé — notamment
`argv`, `command`, `workspace`, `repository`, `runtime`,
`port`, `bind_ipv4` — est rejetée avant construction du plan.

Le résultat est une structure de données avec
`ready_to_execute=false` et des contrôles obligatoires encore non
effectués. **La validation de schéma ne vaut pas autorisation de déploiement.**

## Phase 2B — travaux nécessaires avant exécution

1. Sur l'agent, lire la politique locale dans un emplacement root-only
   distinct des paramètres du serveur central. Valider propriétaire,
   permissions, absence de liens symboliques et cohérence de l'identité
   d'enrôlement. Ne jamais dériver une autorisation de la base SQL centrale.
2. Depuis une source Git fixe, valider HTTPS, la branche protégée,
   l'existence du SHA exact et son appartenance à `main`, ainsi que
   l'ascendance de `minimum_revision`. Ne jamais traiter un contenu Git
   non validé comme une commande de confiance.
3. Contrôler qu'un seul projet utilise le workspace attendu, que celui-ci
   est canonique et sous `/srv/devmcp-hosting`, sans symlink, et utiliser
   un utilisateur d'exécution dépourvu de droits sur `caseRouter`.
4. Vérifier que l'adresse de bind est bien celle de l'interface ZeroTier
   locale (et non `0.0.0.0`), et que le port est libre **et réservé au
   déploiement demandé**. La réservation centrale seule ne prouve
   jamais l'absence de conflit au lancement.
5. Préparer un service applicatif confiné : commande entièrement définie
   localement, aucune commande transmise par MCP, quotas d'exécution,
   logs bornés, installation atomique et rollback idempotent, contrôles
   HTTP et WebSocket depuis un **autre pair ZeroTier**.
6. Compléter l'autorisation de déploiement dans un canal séparé de la file
   `remote-readonly-job`. Exiger un jeton d'opération non rejouable,
   un verrou par déploiement, une réponse auditable, une confirmation
   d'état à l'agent et la gestion des opérations interrompues.
7. Regrouper lors de l'intervention initiale sur `case` : activation de
   l'auto-update `devmcp-agent-update.timer`, création de l'espace
   isolé, validation de la politique de publication et installation des
   services de publication **sans toucher aux fonctions routeur**.

## Phase 2B partielle — preuve Git sans exécution

`RemoteHostingGitProof::verify()` fournit désormais un contrôle de
provenance sur un **checkout local déjà récupéré de manière fiable**.
Il refuse les dépôts modifiés ou shallow, les symlinks de checkout,
une origine différente de `https://github.com/Cowprod/<repo>.git`,
les branches `origin/main` manquantes, les objets non-commit,
les révisions antérieures au `minimum_revision` local et les révisions
étrangères à la branche distante `main`.

L'exécution Git passe par des arguments fixes (sans shell), avec délai
maximal et variables `GIT_*` nettoyées. Aucune action Git réseau ni
activation de service n'est réalisée par cette classe. Les tests utilisent
un dépôt Git temporaire et ses commits synthétiques.

**Reste obligatoire avant la publication réelle** : un fetch HTTPS récent
et authentifié par le composant privilégié, un dépôt physique root-only,
la validation locale des interfaces ZeroTier, des ports et de l'isolation,
ainsi que les verrous, le fencing et le rollback applicatif.
La preuve renvoie explicitement `ready_to_execute=false` même en cas
de succès : elle ne doit jamais être confondue avec un ordre de déployer.

## Phase 2C — brouillons de services systemd isolés, sans activation

`RemoteHostingUnitDraft::render()` produit trois variantes **inertes**
de service systemd, exclusivement à partir d'un plan validé en 2A.
Aucune installation, écriture, commande, connexion réseau ou réservation
de port n'est faite par ce composant. `ready_to_install=false` persiste.

| Runtime | Démarrage imposé localement | Arborescence attendue |
| --- | --- | --- |
| statique | `/usr/bin/python3 -m http.server` | `current/public/` |
| PHP | `/usr/bin/php -S` (pilote de laboratoire seulement) | `current/public/` |
| Node/WebSocket | `/usr/bin/node` | `current/server.js` |

Les ports 12000–19999 et l'adresse IPv4 de bind sont ceux de la
**politique locale**. Pour Node, `HOST` et `PORT` sont exportés mais
l'application doit encore prouver qu'elle s'y conforme. Le service
utilise `DynamicUser`, `NoNewPrivileges`, un jeu de capacités vide,
un système de fichiers protégé, des restrictions sur les familles de
sockets et les bind autorisés, ainsi que des limites mémoire/processus.
Ce modèle limite le risque sans constituer un confinement applicatif
intégral ; les accès sortants et le comportement réseau des applications
restent à auditer.

La commande `php -S` est un serveur de développement, **pas une
solution PHP de production**. Avant tout usage autre que ce lab privé,
prévoir un serveur PHP approprié, durcissement et revue de charge.

Il reste impératif de vérifier avant installation : appartenance de
l'IP à l'interface ZeroTier, port libre et réservé localement,
répertoire `current` et releases non modifiables par l'agent,
dépendances réellement installées, preuve Git sur un checkout root-only,
mécanisme d'activation atomique et rollback, contrôle HTTP/WebSocket
depuis un autre pair ZeroTier, politique root-only et canal d'action
distinct avec anti-rejeu. Les unités ne sont pas distribuées sur `case`.

## Compatibilité avec l'auto-update

L'auto-update introduit en PR #106 met à jour les six binaires PHP
de la file distante actuelle. Le validateur de cette phase est dans
`src/Project` et n'est pas encore inclus dans l'agent distribué.
L'installation future devra donc inclure explicitement le code de
publication, sans prétendre que la PR #106 a déjà équipé `case`.

Aucune nouvelle permission locale ni ouverture de port n'est créée par
cette phase.


## Phase 2D — transaction de release et rollback (contrat pur)

`RemoteHostingReleaseTransaction` est un automate **sans effet de bord**
pour documenter le comportement attendu du futur exécuteur root-only.
Il dérive exclusivement de `RemoteHostingReleasePlan::preview()` et des
brouillons de service validés : un `operation_id` hexadécimal, un SHA exact
de release et éventuellement un SHA précédent. Les chemins sont calculés
sous `/srv/devmcp-hosting/<project>/releases/<sha>` ; le seul pointeur
actif prévu est `current`. Ni chemin ni commande arbitraire ne sont acceptés.

Séquence normale : `awaiting_stage → staged → awaiting_health → healthy`.
Un échec de préparation termine **avant** toute bascule. Si la bascule
ou le redémarrage sont incertains, ou si le contrôle HTTP/WebSocket depuis
un autre pair ZeroTier échoue, l'état devient `rollback_required`.
Pour une mise à jour, on prévoit la restauration de l'ancienne release ;
pour un premier déploiement, la désactivation du service et le retrait du
pointeur `current`. Tant que le rollback n'est pas vérifié, le verrou ne
doit pas être levé. Si sa vérification échoue, état
`manual_recovery_required` : intervention locale et verrou conservé.
Les événements répétés ou hors ordre sont refusés.

**Ce n'est pas un moteur de déploiement.** Les événements de transition
ne peuvent provenir que d'un futur exécuteur **local de confiance** avec
preuve durable ; une requête MCP ne constitue jamais une preuve. Le modèle
ne fournit ni journal durable, ni garantie anti-rejeu, ni changement
atomique d'un lien symbolique, ni service systemd, ni contrôle de santé.
Un déploiement réel exige encore : politique et dépôt root-only vérifiés,
fencing et verrou persistants, fetch Git contrôlé, vérification de port et
de l'interface ZeroTier, séquence d'activation atomique + récupération
après crash, contrôle de santé depuis un pair distinct et canal d'actions
expressément approuvé. Les résultats conservent
`ready_to_execute=false` en toutes circonstances.

Aucune action de déploiement n'a été ajoutée à la file distante
`remote-readonly-job` et aucune configuration de `caseRouter` n'est modifiée.
