# Bootstrap central unique (TouchDeck) — hébergement des trois démos

**Statut : code proposé, installation privilégiée non attestée.** Cette
procédure concerne uniquement TouchDeck. Elle ne contacte pas caseRouter,
ne modifie pas son réseau et n'active aucune des trois applications.

## Préconditions root-only

L'administrateur de TouchDeck doit avoir **déjà et séparément** autorisé
dans le fichier root-owned `/etc/devmcp-admin/distributed.php` :

- `remote_hosting_queue_enabled => true`, avec les identifiants du writer SQL ;
- `hosting_lab_operator_socket_enabled => true` ;
- `hosting_lab_operator_machine_name => 'caseRouter'`.

Ces drapeaux ne sont **jamais écrits** par le bootstrap ni par une
mise à jour automatique. Les migrations SQL, l'enrôlement, les droits
locaux et les trois affectations restent des contrôles indépendants.

Avant toute écriture, le script refuse un chemin d'exécution différent de
`/opt/devmcp/deploy/distributed/bootstrap-hosting-central.sh`, vérifie
la propriété root, les permissions et le nombre de liens des sources Bash/PHP,
des templates systemd et de la configuration, puis exige les deux
approbations indépendantes et l'absence de conflits d'unités systemd.

## Une seule commande de bootstrap central

Après revue du code et approbation locale **sur TouchDeck uniquement** :

```bash
sudo /usr/bin/bash /opt/devmcp/deploy/distributed/bootstrap-hosting-central.sh --root-approved
```

Le script appelle, dans cet ordre, les deux installateurs root-only
déjà présents :

1. `enable-hosting-central-routes.sh` : les deux routes HTTPS
   `hosting-poll` et `hosting-result` dans le vhost ZeroTier contrôlé,
   avec validation Nginx, rechargement et restauration sur échec.
2. `install-hosting-central-operator.sh` : deux unités systemd réservées
   au socket local, et vérification de ses ACL root:`devmcp`.

**Ce n'est pas une transaction unique entre Nginx et systemd.** Si
l'installation du socket échoue après celle des routes, les routes HTTPS
explicitement autorisées peuvent rester présentes ; aucun succès global
n'est annoncé. Chaque sous-installateur dispose de ses propres
contrôles, verrous et mécanismes de rollback. Il faut consulter les
erreurs et corriger les préconditions avant une relance, sans changer
les politiques root-only.

L'activation des actions devMcp `hosting.lab.inspect`,
`hosting.lab.prepare` et `hosting.lab.activate` dans
`/etc/devmcp/global.php` reste **un troisième consentement
administratif distinct**. Le script ne touche pas aux actions projet,
à la base SQL ni aux applications.

## Hygiène des processus privilégiés

Les trois installateurs root utilisent un `PATH` système fixé avant leur
première commande externe et effacent les variables héritées qui pourraient
rediriger PHP ou charger une bibliothèque dynamique. Le test de non-régression
injecte un faux exécutable `id` dans `PATH` et vérifie qu'il n'est jamais
appelé, même en cas de refus d'approbation.

La protection commence **après** le chargement initial de Bash : une variable
`BASH_ENV` pourrait déjà avoir été évaluée. Conserver une politique `sudo`
d'environnement réinitialisé (`env_reset`), sans `sudo -E`, et ne lancer
que des scripts déjà approuvés par root. Le renderer PHP de Nginx doit en plus
être exécuté exclusivement depuis son snapshot privé, après vérification
de son empreinte Git. Ce snapshot doit être supprimé même si le rechargement
Nginx échoue.

## Preuves requises avant déploiement

Après installation autorisée, vérifier sur TouchDeck les routes actives,
le socket et ses ACL, puis les actions réellement autorisées. Inspecter
les affectations et l'absence de jobs en cours avant d'enfiler les trois
préparations à la même révision Git. N'activer qu'après preuve des trois
préparations réussies ; les vérifications HTTP statique :17601, PHP
:17602 et HTTP/WebSocket Node.js :17603 doivent venir d'un pair ZeroTier
**indépendant** et être associées au commit local signé.

Une mise à jour du checkout, les tests CI ou la présence du socket ne
constituent **jamais** une preuve de déploiement.

## Validation de code

`tests/distributed/hosting-central-bootstrap-smoke.sh` vérifie le
refus des invocations non approuvées et l'ordre des contrôles avant la
première mutation. La CI exécute `bash -n` et ShellCheck sur le
script. Le test ne remplace pas une installation systemd réelle.

**État terrain à la rédaction : 0/3 applications attestées.**
