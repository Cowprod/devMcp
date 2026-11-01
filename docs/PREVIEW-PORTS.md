# Ports HTTP de prévisualisation (jalon local)

But : permettre de valider une interface applicative directement dans le navigateur
via `http://<IP-ZeroTier-machine>:<port>`. Ce port **n'est pas** le port de
transport MCP ni un port public Internet.

## Attribution automatique (TouchDeck)

La création MCP `project_create` / `project_register` conserve son interface.
Quand la politique `preview_ports.enabled` est activée, le passage à
`machine_assigned` sur **l'hôte local** réserve automatiquement un port
aléatoire dans la plage configurable (par défaut **12000–19999**).

- La migration `migrations/20261101_preview_port_reservation.sql` doit être
  appliquée avant l'activation. L'API refuse la création **avant GitHub** si
  l'option est activée et que la table manque.
- Le serveur vérifie un bind TCP sur `0.0.0.0:<port>`, puis insère une réservation
  durable dans MariaDB. `UNIQUE(machine,port)` arbitre les créations concurrentes.
  La clé primaire `project_id` conserve un seul port par projet.
- Le port reste inchangé à la reprise ou au redéploiement. La réaffectation
  vers une autre machine supprime l'ancienne réservation dans la même transaction.
- Les réservations sont **par machine** ; deux machines différentes peuvent
  utiliser le même numéro.
- Aucun service n'est démarré automatiquement et aucun pare-feu n'est modifié.
  Le socket de vérification est fermé aussitôt : il subsiste une fenêtre de course
  avant le bind effectif de l'application. Le démarrage du service devra confirmer
  la disponibilité finale.

`project_describe` et `project_list` renvoient `preview_port` ainsi que
`preview_port_state=reserved` quand il existe. Cet état ne signifie **pas**
que l'application tourne, ni que l'URL HTTP fonctionne.

## Interface d'administration

L'inventaire tente de lire les réservations en lecture seule. Pour un projet sur
TouchDeck, il construit `http://192.168.194.189:<port>` à partir de l'adresse
ZeroTier connue ; la clé optionnelle `preview_zerotier_ipv4` dans la configuration
de l'admin permet de la modifier. Le lien porte explicitement la mention
« Port réservé · service non vérifié ».

Le compte SQL de lecture de l'admin doit disposer de `SELECT` sur la nouvelle
table ; aucune autorisation d'écriture ne lui est nécessaire.

## Activation progressive

1. Appliquer la migration via la procédure SQL contrôlée de devMcp.
2. Accorder `SELECT` sur `dm_preview_port_reservation` au compte Web admin.
3. Dans `/etc/devmcp/global.php` (hors dépôt), configurer :

   ```php
   'preview_ports' => [
       'enabled' => true,
       'min' => 12000,
       'max' => 19999,
   ],
   ```

4. Redémarrer les points d'entrée MCP et vérifier leur révision déployée.
5. Créer un **projet jetable** et vérifier la présence de `preview_port`,
   la persistance après redémarrage puis la disponibilité HTTP d'une application
   de test réellement démarrée sur ce port.

La réservation reste purement descriptive tant que l'application n'est pas
configurée pour écouter sur `0.0.0.0:<port>` ou directement sur son IP ZeroTier.
Un serveur lié à `127.0.0.1` n'est pas joignable par ZeroTier.

## Limites de ce jalon

- **Agents distants** : la réservation est volontairement absente tant que
  l'agent ne fournit pas sa propre attestation de disponibilité TCP. Le MCP
  central ne prétend jamais vérifier à distance les sockets d'un agent.
- **Projets historiques** : aucun port n'est attribué rétroactivement sans
  activation explicite de la politique et nouveau flux de provisionnement.
- **État de santé** : l'UI affiche une réservation, pas un contrôle HTTP.
  L'écoute et la réponse de l'application restent à instrumenter.
- **ZeroTier** : l'adresse des agents distants doit être découverte auprès
  de l'agent ; il ne faut pas utiliser l'adresse de TouchDeck par défaut.
- Aucun reverse proxy HTTPS ni ouverture WAN en V1.
