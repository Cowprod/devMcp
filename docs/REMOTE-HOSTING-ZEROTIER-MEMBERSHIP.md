# Hébergement distant — preuve ZeroTier indépendante (phase 2H, inerte)

Le nom d'une interface Linux `zt…` et sa présence dans `net_get_interfaces()`
ne prouvent pas une adhésion ZeroTier autorisée. La classe
`RemoteHostingZeroTierMembership` vérifie donc une deuxième source **locale** :
le démon ZeroTier, via `zerotier-cli -j listnetworks`.

## Contrôles

- Le préflight commence par `RemoteHostingBindEvidence::inspect()`, qui
  vérifie l'adresse IP réellement présente sur une interface Linux active.
- La commande est un argv fixe, sans shell, ni `sudo`, ni argument MCP. Les
  chemins candidats sont fixes (`/usr/sbin/zerotier-cli` ou
  `/usr/bin/zerotier-cli`). La réponse est limitée à 64 KiB et 5 secondes.
- La réponse doit contenir **une seule** adhésion au nom d'interface observé
  par le noyau, un ID réseau de 16 caractères hexadécimaux, un statut `OK`,
  aucun `portError` signalé, et une adresse IPv4 assignée à l'identique
  (CIDR de préfixe 0 à 32).
- Les affiliations contradictoires, les IP dupliquées sur une autre interface,
  les formats malformés et les appartenances non autorisées sont rejetés.
- Le JSON n'est **pas** une entrée admise depuis le MCP. La méthode
  `check()` accepte des snapshots uniquement pour les tests purs ; en
  production, seule `inspect()` collecte les données locales.

## Frontières de sécurité et reste à faire

Une vérification positive est uniquement un **constat à un instant donné**.
Elle ne valide pas le réseau ZeroTier **approuvé par l'administrateur**.
Le `network_id` retourné est désormais comparé par
`RemoteHostingZeroTierNetworkPin::inspect()` au champ obligatoire
`zerotier_network_id` de `/etc/devmcp/hosting-policy.json`, vérifié root-only
par `RemoteHostingLocalPolicy::preview()`. L'identifiant n'est jamais accepté
depuis la demande distante. La concordance d'interface et l'adresse IP ne suffisent
pas à exclure un autre réseau ZeroTier.

Les droits de lecture nécessaires au démon peuvent ne pas être accessibles
à l'utilisateur non privilégié `devmcp-agent` : c'est le futur exécuteur
local explicitement autorisé, et non la file distante de lecture seule,
qui devra invoquer cette vérification. Aucun privilège supplémentaire n'est
installé par cette tranche.

Restent également la réservation et la vérification effective du port,
l'isolation des releases Git, le superviseur applicatif, le journal durable,
le rollback et un contrôle HTTP/WebSocket depuis **un autre pair ZeroTier**.
Le résultat conserve **`ready_to_execute=false`**. Aucune commande de
déploiement n'est ajoutée, aucune application n'est lancée et aucune
configuration de réseau, route, NAT ou pare-feu n'est modifiée.
