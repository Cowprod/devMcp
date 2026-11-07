# Exemple statique de publication distante (inerte)

Ce dossier contient une **source de démonstration**, pas un déploiement.
Le seul contenu à publier est `public/index.html`. Aucun JavaScript,
secret, dépendance ou appel réseau extérieur n'est nécessaire.

## Contrat avec devMcp

Le modèle `RemoteHostingUnitDraft` lance pour `runtime=static` le
serveur Python standard, avec `--directory <workspace>/current/public`,
en liant **uniquement** l'IPv4 ZeroTier approuvée dans la politique locale.
Pour la première publication réelle, une source Git autonome dans un
dépôt `Cowprod/<nom>` doit contenir ce `public/index.html` à sa racine
de release. **Ne jamais pointer le runtime statique sur la racine de
`Cowprod/devMcp` : elle contient d'autres fichiers non destinés à être
publiés.** Le fichier de cette fixture ne vaut pas release approuvée.

La validation automatisée locale, sans privilège ni hôte distant, est :

```sh
python3 tests/distributed/remote-hosting-static-smoke.py
```

Elle vérifie les fichiers publiables, l'absence de ressources externes,
la politique CSP et HTTP 200/404 sur `127.0.0.1` avec un port éphémère.
Elle **ne prouve pas** l'occupation réelle du port, la sécurité de
l'interface ZeroTier ni l'accessibilité depuis un second pair.

## Critères avant installation sur caseRouter

L'exécuteur root-only et la politique locale opt-in doivent valider
identité machine, réseau ZeroTier et IPv4, dépôt Git, SHA sur `main`,
intégrité de la release et droits, exclusivité du port, non-rejeu,
journal durable, rollback et contrôle HTTP depuis **un autre pair**.
Aucun service ne doit être lancé par la seule présence de cet exemple.
Les règles WAN/LAN/NAT/pare-feu restent hors périmètre.

Suivi : issue #138 du dépôt `Cowprod/devMcp`.
