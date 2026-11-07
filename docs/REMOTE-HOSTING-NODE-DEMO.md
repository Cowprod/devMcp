# Démonstrateur distant Node.js / WebSocket (inerte)

Source : `examples/remote-hosting-node/server.js` (fichier unique, sans
`node_modules`, sans dépendance et sans accès disque). Ce répertoire
est un **exemple**, pas une application installée sur `caseRouter`.

Le serveur refuse de démarrer si `HOST` n'est pas une adresse IPv4
spécifique (notamment `0.0.0.0` est interdit) ou si `PORT` n'est pas
un entier entre 12000 et 19999. L'unité future doit fournir ces
variables depuis la politique **locale** root-only. Elle n'accepte
aucune adresse ni commande depuis MCP.

Routes : `GET /` retourne une page HTML statique ;
`GET /healthz` renvoie un JSON sans données privées ;
`GET /ws` avec upgrade WebSocket accepte uniquement des messages
texte complets de **125 octets maximum** et répond par écho. Ping/Pong
est implémenté. Toutes les trames client doivent être masquées. Les
fragments, extensions, grands messages, payloads UTF-8 invalides et
opcodes non prévus sont rejetés. Les connexions sont bornées et
interrompues après inactivité.

**Ce protocole minimal n'est pas un serveur WebSocket de production** :
pas de gestion des messages longs, compression, backpressure avancée,
authentification ni TLS applicatif. Il est prévu exclusivement pour
démontrer les prérequis du laboratoire ZeroTier isolé. Il ne doit
traiter aucune information sensible.

Test CI non privilégié :

```sh
python3 tests/distributed/remote-hosting-node-ws-smoke.py
```

Il démarre Node uniquement sur `127.0.0.1` avec un port de test,
vérifie la santé HTTP, le refus d'un bind wildcard, la négociation
WebSocket RFC 6455, un écho de trame masquée et Ping/Pong, puis arrête
le processus.

Pour publier plus tard, utiliser un **dépôt Git Cowprod autonome**
dont la racine de release contient `server.js`. N'exposez jamais la
racine du dépôt `devMcp`. L'exécuteur privilégié, le SHA Git sur
`main`, le confinement systemd, l'attestation ZeroTier, le verrou
anti-rejeu, le rollback et le test WebSocket depuis un **second pair**
demeurent obligatoires. Aucune de ces garanties n'est fournie par le
seul test CI local. Aucun changement réseau ni permission sur
`caseRouter` n'est autorisé ici.

Suivi : issue `Cowprod/devMcp#138`.
