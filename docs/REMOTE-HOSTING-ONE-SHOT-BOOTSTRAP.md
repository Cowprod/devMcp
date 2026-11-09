# Bootstrap local unique — laboratoire d'hébergement caseRouter

**État : code préparatoire non exécuté sur caseRouter.** Cette procédure
s'applique uniquement après validation de la chaîne centrale (SQL 006/007,
trois affectations actives et même identité machine). Ne pas déclencher
avant d'avoir les trois identifiants `dm_deployment.id` réels.

## Préparatifs hors routeur

Le central doit avoir les migrations `006_remote_hosting_jobs.sql` puis
`007_remote_hosting_signed_commit.sql`, le drapeau
`remote_hosting_queue_enabled=true` et trois déploiements actifs
attribués à l'agent enrôlé. Les projets sont **exactement**
`lab-static`, `lab-php` et `lab-node`. Ils utilisent
**tous** le dépôt `Cowprod/devMcp` à un même SHA immutable de `main` :
les sous-arbres Git sont déterminés par le runtime, pas par le réseau.
Le pair indépendant ZeroTier doit conserver la même clé HMAC de 32 octets
dans un fichier local 0600. Le secret ne doit **jamais** être enregistré
dans Git, un job SQL, un message MCP ou les logs.

## Configuration locale explicite (admin root)

Le fichier de consentement root-only
`/root/devmcp-hosting-bootstrap.json` doit contenir **exactement** :

```json
{
  "version": 1,
  "bind_ipv4": "<IPv4 ZeroTier de caseRouter>",
  "zerotier_network_id": "<16 caractères hexadécimaux>",
  "source_peer_ip": "<IPv4 d'un autre pair ZeroTier>",
  "peer_key_hex": "<64 hex du même secret HMAC du pair>",
  "deployment_ids": {
    "static": "<id dm_deployment 32 hex>",
    "php": "<id dm_deployment 32 hex>",
    "node": "<id dm_deployment 32 hex>"
  },
  "ports": {
    "static": 17601,
    "php": 17602,
    "node": 17603
  }
}
```

**Ne pas utiliser ce modèle tel quel.** Il faut les adresses et IDs réels,
uniquement après approbation explicite. Fichier root-only `0600`.
Le script ne connaît aucun secret distant et ne télécharge aucun
credential depuis le central.

Une fois le code fusionné, l'auto-update de l'agent peut apporter
le bundle immutable ; il ne l'exécute pas. Le **seul** appel privilégié
local à préparer (pas encore demandé) est :

```bash
sudo /bin/bash -c 'set -euo pipefail; sha=$(cat /var/lib/devmcp-agent-update/installed-revision); [[ "$sha" =~ ^[a-f0-9]{40}$ ]]; exec /usr/bin/php "/opt/devmcp-agent/bin/hosting-bundles/$sha/devmcp-hosting-root" bootstrap-lab < /root/devmcp-hosting-bootstrap.json'
```

La redirection d'entrée est exécutée par le shell root, après validation
syntaxique du SHA de l'agent. Le fichier secret n'est jamais placé sur
la ligne de commande.

## Effets bornés

La commande refuse l'identité machine non enrôlée, le checkout
auto-update non conforme, un SHA qui ne correspond pas au bundle,
un changement concurrent d'auto-update, tout dépôt hors
`Cowprod/devMcp`, port dupliqué, adresse hors RFC1918,
policy préexistante différente, symlink ou fichier non root.

Elle initialise les trois workspaces `/srv/devmcp-hosting/lab-*`, les
trois miroirs Git bare, écrit `/etc/devmcp/hosting-policy.json` (0600),
`/etc/devmcp/hosting-peer-proof.json` (0600), puis prépare les releases
et les unités systemd applicatives vérifiées. Elle installe uniquement
le socket root `devmcp-hosting.socket` et le timer d'expiration local ;
`/etc/devmcp/hosting-agent-policy.php` (0644) est publié **en dernier**,
pour n'autoriser l'interrogation de la file qu'une fois l'IPC prêt.

Aucun des trois services applicatifs n'est démarré pendant ce bootstrap.
En cas d'échec avant publication finale, les fichiers de politique
éventuellement créés restent root-only pour inspection : pas de reset
automatique, pas d'autorisation MCP contournée. Le script est relançable
uniquement avec des grants *identiques*. Aucun fichier de caseRouter,
interface WAN/LAN, route, NAT, pare-feu ou service critique n'est modifié.

## Mise en service après bootstrap

Le serveur central envoie **uniquement** `prepare-all`, puis
`activate-initial` et `commit-initial` au travers de la file
hosting opt-in, avec une attestation HMAC provenant d'un pair ZeroTier
indépendant. Le watchdog root local annule un premier déploiement
non attesté après 15 minutes. Les résultats SQL / ACK d'agent ne
constituent pas une preuve terrain. Le déploiement reste 0/3 tant que
les trois vérifications depuis un second pair ne sont pas produites.
