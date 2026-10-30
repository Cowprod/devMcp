# Administration Android / ADB (touchdeck)

## Fonctionnement

Ce module est indépendant de MultiCam : **aucun APK, vidéo ou dossier Capture
n'est modifié**. L'utilisateur devmcp possède déjà un ADB autorisé sur touchdeck.

Le navigateur (PHP-FPM sous www-data) n'exécute jamais ADB. Un service systemd
sous devmcp publie, à fréquence bornée, un instantané JSON des appareils.
Le tableau indique le numéro de série, le modèle, la version Android, l'état
ADB, le pourcentage de batterie et l'espace libre disponible (si ces deux
mesures sont accessibles).

Le bouton Capture d'écran crée un fichier `.req` vide, nommé avec un identifiant
aléatoire et un numéro de série autorisé par l'inventaire récent. Le service
devmcp traite au plus quatre demandes par passage après revérification que
la tablette est réellement connectée et autorisée. La capture est réalisée
par `adb -s SERIAL exec-out screencap -p` sans shell et sa signature PNG est
vérifiée avant publication. Les fichiers PNG sont disponibles uniquement via
la page HTTPS authentifiée (route `/?adb_image=SERIAL`).

Aucun dossier Android n'est effacé ou exploré par ce service. Aucune opération
d'écriture ADB (`push`, `install`, `rm`, `am force-stop`) n'est exposée.

## Fichiers, permissions

Le pont utilise `/var/lib/devmcp-admin-bridge`, séparé du répertoire
privé `/var/lib/devmcp` qui n'est pas accessible à www-data.

- `projects.json` : métadonnées du runtime local (0640).
- `android.json` : inventaire et détails du dernier passage (0640).
- `requests/` : permissions de création réservées à www-data ; fichiers
  vides à nom contraint, jamais de commande arbitraire.
- `screenshots/` : PNG créés par devmcp, lus par www-data (0640).

Répertoires : propriétaire devmcp, groupe www-data ; bit setgid. Les
fichiers sont publiés par renommage atomique. Le processus web ne reçoit
pas le droit de lecture de `/etc/devmcp/global.php` ni le droit d'exécuter
ADB. Une capture n'est acceptée qu'avec un CSRF valide et un appareil vu
comme `device` dans un instantané âgé de moins de deux minutes.

Le service est limité par des délais d'exécution ADB et par une taille
de PNG maximale de 12 Mio. La file web est limitée à 20 demandes
(une demande par appareil). Les demandes abandonnées expirent.

## Activation

Installer d'abord **les mêmes commits testés** dans les deux checkouts :
`/opt/devmcp` (MCP) et `/opt/devmcp-admin` (application web).
Ne pas écraser les configurations `/etc/devmcp` ni `/etc/devmcp-admin`.

Une fois ces deux installations synchronisées, lancer :

```sh
sudo bash /opt/devmcp/deploy/distributed/install-local-admin-bridge.sh
```

Le script vérifie la présence des deux copies, les groupes système, la syntaxe
PHP, les permissions de publication et active les services/timers systemd.

Contrôles de lecture possibles, sans intervention sur les tablettes :

```sh
systemctl status devmcp-admin-projects.timer devmcp-admin-android.timer --no-pager
```

L'inventaire est une observation périodique : son horodatage UTC est toujours
visible. Si ADB est hors service, la page doit annoncer l'indisponibilité et
ne pas permettre de demander une capture.

## Limites de la première livraison

- Pas de streaming de l'écran, seulement un PNG sur demande.
- Pas de contrôle à distance tactile.
- Pas de consultation de logcat et de copie de fichiers dans cette version.
- Les captures précédentes sont remplacées pour un numéro de série donné.
- Un utilisateur avec accès administrateur HTTPS voit les captures réalisées.

## Ergonomie et identification des appareils

- Après la demande d'une capture, la page reste sur la section Android/ADB.
  L'état « En attente » expose un bouton d'actualisation et une interrogation
  HTTPS du statut toutes les 3 secondes (80 passages au maximum) ; l'image est
  insérée sans recharger toute la page une fois la demande terminée.
- Les aperçus existants ouvrent une fenêtre modale avec téléchargement PNG.
  Le navigateur ajoute un paramètre de cache distinct à chaque nouvelle image.
- Un libellé local libre (par exemple « Capture B », « Master » ou « Storage »)
  peut être enregistré par l'administrateur pour chaque numéro de série ADB.
  Il est conservé côté serveur dans le dossier privé
  `/var/lib/devmcp-admin-bridge/labels`, propriété de `www-data`.
  Le numéro de série reste la seule clé technique ; ce libellé ne modifie
  ni le nom ni le rôle de la tablette dans MultiCam.
- Le worker lit séparément `getprop net.hostname` (hôte réseau, parfois vide)
  et `settings get global device_name` (nom Android défini dans les paramètres).
  Si aucune information n'est fournie par l'appareil, la colonne indique « — ».
- Le point de lecture `/?adb_status=SERIAL` renvoie uniquement l'état
  `pending/ready/error/mtime` d'une capture d'un appareil connu, sous la même
  authentification HTTPS que l'administration. Aucun accès anonyme aux images
  ou aux états n'est exposé.

Après mise à jour du code dans les **deux** installations, le script de
déploiement `install-local-admin-bridge.sh` crée le dossier `labels` avec
les permissions adéquates. Les libellés survivent aux redémarrages et aux
mises à jour Git, et ne sont pas intégrés à l'inventaire ADB régénéré.
