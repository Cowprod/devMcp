# TouchDeck : disponibilité HTTPS après démarrage ZeroTier

## Incident observé (7 novembre 2026)

L'agent distant `case` effectuait bien sa mise à jour automatique, mais ses
requêtes vers le central échouaient (`curl_errno=7`, TCP 443 refusé).

Sur TouchDeck, Nginx avait échoué lors du démarrage le **5 novembre 2026** :
`bind() to 192.168.194.189:443 failed (99: Cannot assign requested address)`.
L'IP ZeroTier n'était pas encore présente. Une fois Nginx démarré manuellement
le 7 novembre, le ping authentifié de l'agent est revenu et les anciens jobs
distants ont été consommés. Cela démontre une course au démarrage, **pas**
un défaut de l'auto-update de l'agent.

## Correction persistante

Nginx conserve exclusivement `listen __ZT_IP__:443 ssl;`, dans
`deploy/distributed/nginx-admin.conf.example`. Il ne faut **pas** le
remplacer par `listen 443` ou `0.0.0.0:443`, qui élargiraient
l'exposition au-delà de ZeroTier.

`deploy/distributed/nginx-zerotier-retry.conf` définit une surcharge
systemd pour `nginx.service` :

- `Restart=on-failure` et `RestartSec=10s` ;
- `StartLimitIntervalSec=0`, afin de ne pas épuiser les tentatives en cas
  d'indisponibilité prolongée de l'IP ZeroTier.

`deploy/distributed/install-admin.sh` installe ce fichier sous
`/etc/systemd/system/nginx.service.d/restart.conf` de manière idempotente :
si un fichier existe avec les mêmes octets, il est conservé ; sinon
l'installateur refuse tout remplacement silencieux. Le fichier fourni
correspond exactement à la surcharge posée manuellement sur TouchDeck le
7 novembre. L'installateur exécute `systemctl daemon-reload`, puis son
flux habituel `nginx -t` et `systemctl reload nginx`. Il ne redémarre
ni le serveur ni les interfaces et ne touche pas au pare-feu.

L'installateur doit être exécuté **uniquement** dans le cadre du
provisionnement administrateur TouchDeck déjà approuvé. Cette PR n'exécute
pas elle-même l'installateur sur TouchDeck ou `case`.

## Vérifications

Sur TouchDeck, observer `systemctl show nginx.service -p ActiveState
-p Restart -p RestartUSec -p StartLimitIntervalUSec` et vérifier que
`ActiveState=active`, `Restart=on-failure`, `RestartUSec=10s` et
`StartLimitIntervalUSec=0`.

Le test `tests/distributed/nginx-zerotier-startup-smoke.sh` garantit le
contrat versionné, sans contrôle actif d'un service systemd. La validation
de bout en bout après un **vrai redémarrage** de TouchDeck reste distincte :
la présence de la surcharge ne prouve pas à elle seule la reprise effective
de Nginx dans toutes les conditions de démarrage.

## Portée

Cette correction concerne **le serveur central TouchDeck**, pas le système
`caseRouter`. Elle n'accorde aucune nouvelle action distante et ne démarre
aucun des futurs services d'hébergement statique/PHP/Node. Leur politique
locale root-only et leur activation restent des jalons séparés.
