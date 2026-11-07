# Exemple PHP de publication distante (inerte)

Ce dossier fournit une démonstration sans dépendances ni écriture sur
disque. Il contient uniquement `public/index.php`. Le GET `/`
affiche une page HTML et `/healthz` retourne
`{"ok":true,"runtime":"php"}`. Les méthodes autres que GET/HEAD
sont refusées (405) et les chemins inconnus renvoient 404.

Le runtime de laboratoire préparé par `RemoteHostingUnitDraft`
utilise `/usr/bin/php -S <IP_ZeroTier>:<port> -t <release>/public`.
**Ce serveur PHP intégré est réservé au laboratoire, jamais à la
production.** La source devra être exportée dans un dépôt Git
`Cowprod/<nom>` autonome approuvé par la politique locale. Ne jamais
publier le dossier racine de `Cowprod/devMcp` via ce runtime.

Test automatisé sans privilèges et sans serveur distant :

```sh
python3 tests/distributed/remote-hosting-php-smoke.py
```

Il lance temporairement PHP sur `127.0.0.1` et un port éphémère,
puis vérifie HTTP 200/404/405, JSON, CSP, en-têtes et requête HEAD.
Il arrête le processus dans tous les cas. Ce test ne prouve pas
l'accessibilité depuis un pair ZeroTier.

Avant déploiement réel : exécuteur local root-only séparé des six
actions MCP read-only, politique opt-in liée à la machine, dépôt Git
et SHA validés, fichiers de release immuables, ZeroTier et port
épinglés, rollback transactionnel, service isolé et santé depuis un
second pair ZeroTier. Aucun changement au routeur.

Suivi : issue Cowprod/devMcp #138.
