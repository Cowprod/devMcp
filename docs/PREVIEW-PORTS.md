# Ports HTTP de prévisualisation (jalon local)

But : permettre de valider une interface applicative directement dans le navigateur
via `http://<IP-ZeroTier-machine>:<port>`. Ce port **n'est pas** le port de
transport MCP ni un port public Internet.

## Attribution automatique (TouchDeck)

La création MCP `project_create` / `project_register` conserve son interface.
L'attribution est désormais **automatique par défaut** lorsque
`project_provisioning.enabled=true`. La clé facultative
`preview_ports.enabled=false` permet de la désactiver explicitement.
Le passage à
`machine_assigned` sur **l'hôte local** réserve automatiquement un port
aléatoire dans la plage configurable (par défaut **12000–19999**).

- La migration `migrations/20261101_preview_port_reservation.sql` est
  appliquée **idempotemment par l'auto-update root** avant le redémarrage MCP,
  via `bin/migrate-preview-port-reservations.php`.
  Si le compte SQL d'écriture ne dispose pas du droit `CREATE`, l'update
  échoue explicitement en étape `preview-port-migration` sans attribuer un
  faux port ; il faut alors accorder le droit ou appliquer la migration
  par la procédure SQL de l'installation.
  L'API refuse la création **avant GitHub** si l'option est activée et que
  la table manque.
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

## Déploiement

L'auto-update root applique désormais la migration statique et redémarre les
entrées MCP uniquement si le diagnostic réussit. Aucun nouveau fichier de
configuration n'est requis pour les projets locaux : dès que
`project_provisioning.enabled=true`, les nouvelles créations obtiennent
un port réservé.

On peut personnaliser la plage dans `/etc/devmcp/global.php`, sans changer le
port déjà réservé aux projets existants :

```php
'preview_ports' => [
    'enabled' => true,
    'min' => 12000,
    'max' => 19999,
],
```

L'admin Web reste une frontière de lecture seule ; elle peut nécessiter
un droit `SELECT` supplémentaire pour voir les réservations (le formulaire
continue de fonctionner si le droit manque). Une URL annoncée ne prouve ni
l'écoute du processus ni l'accessibilité ZeroTier. Effectuer un test réel
HTTP après avoir configuré le processus applicatif pour écouter sur ce port.

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
