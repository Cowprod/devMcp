# Hébergement distant : préflight de collision TCP en lecture seule

La classe `RemoteHostingPortEvidence::inspect($request)` complète les preuves de politique locale et d'adhésion au réseau ZeroTier sans modifier les ports, routes, interfaces, règles réseau, services ou fichiers système.

Elle recharge la politique `/etc/devmcp/hosting-policy.json` (root-only), vérifie l'identité enrôlée, la présence de l'interface et l'identifiant du réseau ZeroTier autorisé, puis lit uniquement `/proc/net/tcp` et `/proc/net/tcp6` dans l'espace réseau du processus. Elle refuse une écoute TCP `LISTEN` sur le port épinglé, quelle que soit l'adresse locale (y compris LAN, loopback, IPv4 wildcard ou IPv6 wildcard). Elle refuse également une table absente, volumineuse ou malformée, un plan exécutable, un pin falsifié ou un contrat incomplet. Les données et tables de tests peuvent être injectées uniquement dans la fonction pure `check()`, jamais dans `inspect()`.

**Preuve strictement instantanée** : une absence de socket `LISTEN` ne constitue ni une réservation de port, ni une preuve d'absence de sockets non encore à l'écoute, ni une garantie de bind systemd futur. Avant l'activation, l'exécuteur local distinct devra obtenir un verrou exclusif, vérifier l'affectation persistée du port et son namespace, effectuer le bind sous l'identité du service puis revalider la santé HTTP/WebSocket depuis un autre pair ZeroTier. Aucune opération de ce type n'est réalisée ici.

Le résultat conserve impérativement `ready_to_execute=false`. Il ne doit **pas** être raccordé à la file d'actions distantes actuelle : `caserouter` reste limité aux six inspections autorisées. Aucune autorisation ni capacité de déploiement n'est introduite par ce lot.

Tests : `tests/Distributed/RemoteHostingPortEvidenceTest.php`, trois runtimes, collisions IPv4 et IPv6, port sans collision, états non-`LISTEN`, formats malformés, limite de taille et pin non fiable.
