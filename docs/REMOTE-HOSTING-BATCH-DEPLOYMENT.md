# Déploiement groupé des trois démonstrateurs caseRouter

**État : code central, pas une autorisation de déploiement ni une preuve terrain.**
Le CLI `bin/devmcp-hosting-lab-batch` est un outil **root-only du central**.
Il n'est pas exposé via MCP, HTTP ou la file des actions de lecture seule.
Le mécanisme existant sur caseRouter valide indépendamment chaque tâche
dans sa politique locale et via son socket root opt-in.

## Prérequis

Les migrations MySQL 006/007 doivent être effectivement appliquées, le
drapeau root `remote_hosting_queue_enabled=true` actif dans
`/etc/devmcp-admin/distributed.php`, l'agent caseRouter enrôlé et actif,
et les trois affectations exactes `lab-static`, `lab-php`, `lab-node`
créées par `devmcp-hosting-lab-enroll`. Le bootstrap local root-only du
routeur doit être terminé avant de demander un démarrage.

L'appel `--prepare` accepte exactement le JSON suivant sur stdin :

```json
{"machine_id":"<32 hex du routeur>","revision":"<40 hex Git de main>","approval":"PREPARE_CASE_HOSTING_LAB"}
```

Le résultat ne contient aucun secret : il décrit trois tâches
`prepare-all` en file sur les ports fixes 17601, 17602 et 17603.
Le central verrouille les affectations, exige une identité agent valide,
vérifie le dépôt `Cowprod/devMcp`, les trois workspaces attendus, l'absence
de tâche concurrente et insère les trois lignes dans **une même transaction**.
Une tâche en échec n'est jamais traitée comme un succès de préparation.

Seule une fois les **trois** `prepare-all` déclarées `succeeded` dans
la file, avec la **même révision**, l'opérateur root peut demander
`--activate`, avec une approbation différente :

```json
{"machine_id":"<32 hex du routeur>","revision":"<40 hex Git de main>","approval":"ACTIVATE_CASE_HOSTING_LAB"}
```

La commande enfile alors exactement trois `activate-initial`. Chaque
`operation_id` est égal au `job_id` central et retourné par runtime.
Cette valeur doit être reprise, sans transformation, dans la sonde de santé
indépendante de ce runtime. Une tâche déjà en file ou revendiquée bloque
l'ensemble des trois créations. L'absence de préparation, une préparation
échouée, un SHA différent, un projet désactivé ou des permissions centrale
manquantes **refusent sans insertion partielle**.

## Séquence de validation terrain

Le routeur prépare via l'agent séparément autorisé, puis démarre les unités
systemd de laboratoire uniquement. Un autre pair ZeroTier exécute la
sonde HTTP et WebSocket signée en conservant localement le secret HMAC.
Le central transmet ensuite, **par déploiement**, le `commit-initial`
authentifié. Le routeur vérifie signature, nonce, révision, provenance,
service actif et journal, puis archive la preuve. En l'absence de commit,
le watchdog root local annule une première activation pending au bout de
15 minutes.

**Important :** le retour `jobs_queued=3`, un ACK d'agent ou un
`activate-initial` `succeeded` ne signifient **jamais** « trois sites
déployés ». Il faut les trois preuves HTTP/WS indépendantes et les trois
commits root. Les upgrades restent explicitement hors périmètre de cette
commande (premier déploiement seulement). Aucune action sur les interfaces
WAN/LAN, routes, NAT, pare-feu, services critiques ou checkout
`Cowprod/caseRouter` n'est permise.
