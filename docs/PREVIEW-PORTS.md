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

## Réservation sur les agents distants (protocole expérimental)

Le même endpoint de heartbeat **ZeroTier/TLS authentifié**
`POST /v1/agent/ping` annonce au plus huit projets en état
`machine_assigned` ou `active` affectés à l'agent actif et sans port réservé.
L'agent actualisé effectue lui-même des binds TCP temporaires sur **sa propre
machine** puis soumet jusqu'à 16 candidats. Le serveur valide l'identité
machine, le projet, son état et sa plage de ports, verrouille la ligne
de provisionnement et réserve dans la table **existante**
`dm_preview_port_reservation`. Une contrainte unique SQL (machine,port)
arbitre les collisions concurrentes. La réservation est idempotente.

La configuration du serveur agent se trouve dans
`/etc/devmcp-admin/distributed.php` (optionnelle) :

```php
'preview_ports' => [
    'enabled' => true,
    'min' => 12000,
    'max' => 19999,
],
```

Par défaut, le protocole utilise 12000–19999 ; désactiver avec
`enabled=false`. Les anciens agents continuent leurs heartbeats,
mais ne peuvent pas attribuer de ports distants avant mise à jour avec
`agent-preview-port-probe.php`. Le déploiement des binaires agents
reste une étape de validation distincte.

Une attestation de bind est une observation momentanée :
elle **ne garantit pas** qu'un service démarre ultérieurement, ni qu'un pair
ZeroTier tiers puisse atteindre le service. Ne publier une URL distante
qu'après découverte de l'IP ZeroTier réelle de la machine cible et distinguer
son état HTTP de la réservation SQL.

## Adresse ZeroTier réelle des agents distants

L'agent **mis à jour** recherche une adresse IPv4 sur une interface réseau
Linux active dont le nom commence par `zt`. Il la transmet uniquement après
négociation de la capacité par le serveur (les agents récents restent ainsi
compatibles avec une version ancienne du serveur).

Le serveur compare cette adresse avec la véritable
`REMOTE_ADDR` de la connexion TLS authentifiée reçue sur son écoute
**ZeroTier uniquement**. Une adresse différente, falsifiée, loopback ou
multicast est rejetée : le serveur ne reprend jamais une IP fournie
sans comparaison au pair effectivement connecté.

La migration `migrations/20261102_agent_zerotier_address.sql` crée
`dm_agent_zerotier_address` (une adresse observée et horodatée par identité
machine), automatiquement via la procédure de déploiement privilégiée.
L'admin ne forme un lien distant
`http://<IP-ZeroTier-agent>:<port-réservé>` que pour un projet actif
avec réservation persistante **et** une observation de l'adresse datant
de moins de dix minutes. Le compte SQL de lecture Web doit pouvoir
effectuer un `SELECT` sur cette table ; sinon l'URL distante n'est pas
proposée et l'admin reste disponible.

Cette observation d'adresse vérifie la provenance réseau au moment du
heartbeat. **Elle ne teste pas le HTTP applicatif**, ni ne garantit que
l'agent écoute le port réservé ; ce contrôle doit encore être effectué
depuis un second pair ZeroTier après activation explicite d'un service.

Aucune règle NAT/WAN, proxy HTTPS applicatif ni serveur projet n'est créé.

## Limites de ce jalon

- **Projets historiques** : aucun port n'est attribué rétroactivement sans
  activation explicite de la politique et nouveau flux de provisionnement.
- **État de santé** : l'UI affiche une réservation, pas un contrôle HTTP.
  L'écoute et la réponse de l'application restent à instrumenter.
- **ZeroTier** : l'adresse des agents distants doit être découverte auprès
  de l'agent ; il ne faut pas utiliser l'adresse de TouchDeck par défaut.
- Aucun reverse proxy HTTPS ni ouverture WAN en V1.
