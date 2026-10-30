# Administration Android / ADB (touchdeck)

## Fonctionnement

Ce module est indépendant de MultiCam : **aucun APK, vidéo ou dossier Capture
n'est modifié**. L'utilisateur devmcp possède déjà un ADB autorisé sur touchdeck.

Le navigateur (PHP-FPM sous www-data) n'exécute jamais ADB. Un service systemd
sous devmcp publie, à fréquence bornée, un instantané JSON des appareils.
Le tableau indique le numéro de série, le modèle, la version Android, l'état
ADB, le pourcentage de batterie et l'espace libre disponible (si ces deux
mesures sont accessibles). L'état **MultiCam actif / inactif** est déduit de
`adb shell pidof fr.emmanuel.multicam` et affiche le PID du processus courant.
Il s'agit d'une photographie périodique, pas d'une garantie que l'application
est toujours active au moment où un diagnostic est demandé.

L'administration affiche également la **version réellement installée de MultiCam**
(nom et code de version) sur chaque tablette, même lorsque l'application est
arrêtée. La lecture `adb shell dumpsys package fr.emmanuel.multicam` est bornée
à cinq secondes par appareil ; elle n'installe rien et ne touche ni aux données
ni aux enregistrements. Le parseur n'extrait que la section de ce package, afin
de ne pas confondre ses informations avec celles d'une autre application.
Si le package est absent ou que la lecture échoue, la version reste inconnue.


Le bouton Capture d'écran crée un fichier `.req` vide, nommé avec un identifiant
aléatoire et un numéro de série autorisé par l'inventaire récent. Le service
devmcp traite au plus quatre demandes par passage après revérification que
la tablette est réellement connectée et autorisée. La capture est réalisée
par `adb -s SERIAL exec-out screencap -p` sans shell et sa signature PNG est
vérifiée avant publication. Les fichiers PNG sont disponibles uniquement via
la page HTTPS authentifiée (route `/?adb_image=SERIAL`).
Le bouton **Logs MultiCam** demande, via la même file contrôlée, les 200 dernières lignes
`logcat` du **processus actif** `fr.emmanuel.multicam` seulement. Le worker
résout son PID avec `adb shell pidof` puis utilise `adb logcat -d -t 200 --pid=PID` :
le tampon Android n'est jamais vidé et l'application n'est jamais interrompue.
Si elle n'est pas active, l'interface indique que le journal est indisponible.
Les journaux sont bornés à 130 Ko, accessibles uniquement en HTTPS authentifié
(`/?adb_log=SERIAL`), puis supprimés automatiquement après 15 minutes.
Ils peuvent contenir des informations sensibles émises par l'application ; ne pas
les transmettre à des tiers sans les relire.


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
- `logs/` : diagnostics temporaires (0640), accessibles uniquement aux administrateurs HTTPS.

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
- Consultation de logcat limitée au processus MultiCam actif ; pas de recherche arbitraire dans les journaux système ni de copie de fichiers Android.
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

## Diagnostics et compatibilité

Les journaux ne sont consultables qu'après une demande administrateur avec jeton CSRF.
Un statut « en attente » est affiché dans la colonne Journal MultiCam jusqu'au
prochain passage du service ADB ; une actualisation de page affiche ensuite
le lien de lecture ou l'échec. Le service conserve les aperçus et les libellés
personnalisés ajoutés lors de la livraison précédente.

Le déploiement coordonné se fait automatiquement par `devmcp.update` lorsque
le service de mise à jour est déjà installé et opérationnel ; l'acceptation
d'une demande de mise à jour n'est **pas** une preuve du commit réellement actif.
La version installée dans `/opt/devmcp-admin` doit être vérifiée après déploiement.
