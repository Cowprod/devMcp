# Installer un agent depuis l’admin

Sur Ubuntu/Debian avec systemd et une connexion ZeroTier déjà opérationnelle :

1. Dans l’admin central, ajouter une machine et créer son invitation.
2. Copier la commande d’installation affichée et l’exécuter sur la machine cible.
3. Après l’installation, approuver cette machine dans l’admin.

La commande utilise la révision exacte déployée par l’admin, pour télécharger
l’installateur et pour son argument `--release`. Une révision introuvable empêche
la génération de la commande, sans remplacement par `main` ou un ancien commit.
L’installateur vérifie la route ZeroTier et le certificat central épinglé,
installe les dépendances manquantes, échange le jeton à usage unique, puis
installe le service, son timer, le moteur de projets et sa mise à jour.
Il refuse d’écraser une identité existante. PHP installé doit être ≥ 8.3.
ZeroTier doit être installé et relié au réseau au préalable.

## Créer puis déployer un projet

Après approbation, l’agent annonce `projects-v1:<révision>` dans son ping.
L’appel MCP `project_create` accepte alors :

```json
{"project_id":"mon-projet","description":"Mon application","machine":"rtx","confirmation":"CREATE_PROJECT"}
```

Il crée un dépôt GitHub privé, enregistre le projet et demande à l’agent de
créer son workspace. Attendre que le job retourné réussisse : le projet devient
`active` uniquement après confirmation de l’agent. Aucun dépôt, répertoire ni
réglage par projet ne doit être préparé à la main sur la machine cible.
Le même parcours est accessible via l’action `project.create.remote` du projet
`devmcp-admin`, avec `id`, `machine` et `description`.

Écrire le code dans le dépôt créé, puis appeler `action_start` :

```json
{"project":"mon-projet","action":"project.deploy","arguments":{"runtime":"static","commit":"<SHA exact du commit>"}}
```

Le runtime est explicite : `static` attend `public/index.html`, `php` attend
`public/index.php`, `node` attend `server.js`. Node doit lire `HOST` et `PORT` ;
`DATA_DIR` désigne son répertoire persistant. Ce moteur prend en charge Node
sans dépendances npm. Les sources sont limitées à 4 Mio et 1 000 fichiers
réguliers ; liens Git et sous-modules sont refusés. Les identifiants GitHub
restent sur le central, qui transmet les blobs Git à l’agent.

L’agent choisit un port disponible dans 12000–19999, crée un service systemd et
vérifie HTTP. Le central vérifie à son tour l’URL via ZeroTier avant de valider
le déploiement. `project_describe` retourne l’URL et la révision active.
`project.status`, `project.logs`, `workspace.sync`, `git.head` et les lectures
de workspace passent par la même file. `project.provision` reprend une création
dont le dépôt existe mais dont la préparation des sources a échoué.

Le moteur générique crée ses propres projets sous `/srv/devmcp-projects` et ne
convertit pas les anciens projets en lecture seule. Une installation neuve sur
RTX reste à vérifier sur cette machine réelle.
