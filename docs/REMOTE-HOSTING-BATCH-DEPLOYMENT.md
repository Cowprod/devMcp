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

## Variante root par nom exact de machine

Après un enrôlement `--bootstrap-name`, l'administrateur TouchDeck peut
éviter de recopier manuellement le `machine_id` SQL pour les deux phases.
Le CLI accepte `--prepare-name` avec une approbation distincte :

```json
{"machine_name":"case","revision":"<SHA Git main 40 hex>","approval":"PREPARE_CASE_HOSTING_LAB_BY_NAME"}
```

Après trois préparations `succeeded`, `--activate-name` exige **un autre**
consentement explicite :

```json
{"machine_name":"case","revision":"<même SHA Git main 40 hex>","approval":"ACTIVATE_CASE_HOSTING_LAB_BY_NAME"}
```

Le nom est une valeur exacte de `dm_machine.name`, non une découverte de
réseau : une différence de casse, un nom ambigu, inactif ou révoqué est
refusé **avant toute mise en file**. Le resolver central vérifie une identité
agent valide ; la transaction SQL existante verrouille ensuite de nouveau
la machine, l'identité et les trois grants, sans insertion partielle. Le
résultat contient le `machine_id` réel et les trois `deployment_id`.
L'ancien mode explicite `--prepare` / `--activate` par ID reste supporté.
Les marqueurs d'approbation des deux modes ne sont pas interchangeables.

**Ce mode ne change ni les permissions root, ni le drapeau central d'opt-in,
ni les droits de l'agent.** Il ne fonctionne qu'après l'intervention de root
sur TouchDeck et le bootstrap local déjà autorisé sur caseRouter. Aucune
commande de cette section ne constitue une preuve de déploiement.

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

## Validation signée et commit par runtime (phase suivante)

Une fois les trois jobs `activate-initial` **confirmés succeeded** par
le central (cela ne prouve pas encore le fonctionnement des sites),
exécuter `deploy/distributed/hosting-peer-probe.py` depuis un **autre
appareil ZeroTier** pour chaque runtime, avec la clé HMAC enregistrée
localement sur ce pair (fichier 0600 de 32 octets). La sonde valide le
marqueur HTTP, la source IP réellement liée et, pour Node, le handshake
et l'écho WebSocket. Elle produit un objet JSON signé avec le
`operation_id` exact de l'activation correspondante.

La sonde refuse désormais de signer une observation dont le triplet
`runtime / project_id / port` diverge des trois affectations fixes du
laboratoire (`static / lab-static / 17601`, `php / lab-php / 17602`,
`node / lab-node / 17603`). La clé locale est ouverte avec `O_NOFOLLOW`
(et `O_NONBLOCK` contre un fichier FIFO), puis validée par `fstat` sur
le descripteur réellement ouvert : fichier régulier, propriétaire effectif,
mode `0600`, lien unique et 32 octets. Aucun secret n'est transmis au central.
Le test `tests/distributed/hosting-peer-probe-contract.py` vérifie que les
reçus HMAC signés par le code Python sont acceptés par le vérificateur PHP,
ainsi que les refus de mauvaise affectation / clé suspecte. **Ce test CI
simule la connexion réseau : il ne prouve aucun déploiement sur caseRouter.**

La nouvelle commande centrale root-only est
`bin/devmcp-hosting-lab-commit` (sans argument, approbation JSON
sur stdin, une preuve par invocation) :

```json
{"approval":"COMMIT_CASE_HOSTING_LAB","proof":{"version":1,"operation_id":"<nonce 32 hex>","deployment_id":"<id 32 hex>","machine_id":"<id 32 hex>","project_id":"lab-static","revision":"<SHA 40 hex>","runtime":"static","target_ip":"<IPv4 ZeroTier caseRouter>","source_peer_ip":"<IPv4 ZeroTier du second pair>","port":17601,"observed_at":0,"http_verified":true,"websocket_verified":false,"signature":"<HMAC 64 hex>"}}
```

L'exemple est **un schéma**, pas une preuve exécutable (`observed_at=0`
sera refusé). Il faut envelopper **sans altérer** la sortie JSON réelle
de la sonde dans `proof`, puis l'envoyer immédiatement : le central
refuse une observation âgée de plus de 30 secondes, et l'agent devra
consommer le job sous 120 secondes. Les trois engagements sont
**intentionnellement individuels** : l'agent ne poll qu'un job toutes
les 60 secondes ; mettre trois preuves en file simultanément les
laisserait expirer avant l'authentification root. Répéter la sonde après
chaque commit reçu, et ne considérer un site déployé qu'après retour
succeeded de son `commit-initial` et preuve externe correspondante.

La classe `HostingLabCommitEnqueue` verrouille la machine et le grant
SQL dans une transaction, exige l'enrôlement actif, un
`activate-initial` **succeeded** avec nonce=ID du job le plus récent,
une release et un runtime identiques, aucun job concurrent, puis met
en file exactement un `commit-initial`. Elle interdit le rejeu après
commit central réussi ; un échec/une expiration exige une **nouvelle**
preuve fraîche. Le central ne possède **pas** la clé HMAC et retourne
`peer_hmac_verified=false`, `deployment_complete=false`.
L'authentification réelle et la décision durable de commit restent
uniquement dans l'exécuteur root local de caseRouter ; si le commit
n'aboutit pas avant le watchdog, l'activation initiale est annulée.
Aucun rôle MCP, permission d'agent ou service critique n'est modifié.
