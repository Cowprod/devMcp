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
`devmcp-admin`, avec `project_id`, `machine` et `description`.

`project_register` retourne `registration_succeeded=true`, `state=registered` et
`revision=1` après l’écriture centrale, sans cloner sur TouchDeck. Un appel
identique retourne l’état courant avec `already_registered=true` sans augmenter
la révision ; un changement de description, dépôt ou mode est un conflit explicite.
Le journal de ces étapes est consultable par `audit_tail(project="_provisioning")`.

Si le dépôt existe déjà et que le parcours `project_register` / confirmation du
dépôt / `project_assign_machine` a laissé le projet `machine_assigned`, utiliser
`project_provision_remote` avec la révision du journal et la confirmation
`PROVISION_REMOTE_WORKSPACE`. L’action `project.provision.remote` du projet
`devmcp-admin` expose aussi cette reprise avec le seul argument `project_id`.
Elle conserve le dépôt existant et attend la confirmation de création de l’agent.
`project_provision_local` est réservé à l’hôte central ; il indique explicitement
la reprise distante lorsqu’un projet est affecté à une autre machine.

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

PHP reçoit automatiquement `session.save_path` dans un sous-répertoire privé
`.php-sessions` de son stockage systemd persistant. `session_start()` fonctionne
sans configuration applicative et les sessions survivent au redémarrage ou au
redéploiement. Le moteur local utilise aussi un stockage de sessions par projet.
La mise à jour de l’agent fournit ce comportement aux prochains déploiements ;
un service existant doit être redéployé pour recevoir la nouvelle commande PHP.

L’agent choisit un port disponible dans 12000–19999, crée un service systemd et
vérifie HTTP. Le central vérifie à son tour l’URL via ZeroTier avant de valider
le déploiement. `project_describe` retourne l’URL et la révision active.
`project.status`, `project.logs`, `workspace.sync`, `git.head` et les lectures
de workspace passent par la même file. `project.provision` reprend une création
dont le dépôt existe mais dont la préparation des sources a échoué.

Le moteur générique crée ses propres projets sous `/srv/devmcp-projects` et ne
convertit pas les anciens projets en lecture seule. Une installation neuve sur
RTX reste à vérifier sur cette machine réelle.

## Hébergement sur macOS Apple Silicon

Créer la machine avec OS `macos` et architecture `aarch64` dans l’admin. La
commande générée sélectionne l’installateur macOS, exécuté sous le compte local.
Il détecte Homebrew, PHP et Node et installe les éléments manquants. Homebrew
peut demander un mot de passe administrateur lors de sa première installation.
Un réseau ZeroTier déjà connecté au central et une session utilisateur macOS
ouverte sont nécessaires. Aucun outil Homebrew n’est supposé présent dans le PATH.

Les applications et le poller sont des LaunchAgents. Ils redémarrent après
ouverture de session et les applications sont relancées par launchd en cas
 d’arrêt. Ce mode ne promet pas un démarrage avant la connexion de l’utilisateur.
Les sources sont sous `/Users/Shared/devmcp-projects-<machine-id>/<projet>` ;
`DATA_DIR` désigne leur sous-répertoire `data`, conservé entre les déploiements.
PHP reçoit automatiquement son chemin de sessions persistant. Les actions
`project.deploy`, `project.status`, `project.logs`, les lectures et la
synchronisation utilisent la même file centrale que Linux. Le serveur statique
utilise un serveur Node fourni par l’agent, sans dépendance Python.

L’agent vérifie les TLS avec le certificat central épinglé. Les certificats
et jetons sont stockés dans `~/Library/Application Support/devMcp`. Un deuxième
LaunchAgent vérifie les mises à jour toutes les six heures et conserve les
anciens bundles utilisés par les services existants. Xcode et la génération
d’IPA ne font pas partie de cette première implémentation d’hébergement.

Sur macOS 15 et suivants, le service en arrière-plan doit recevoir l’autorisation
**Réseau local**. L’installateur prépare une application native nommée **devMcp**
et l’associe aux LaunchAgents, pour présenter cette demande sous un nom explicite.
Autoriser devMcp une fois dans Réglages Système → Confidentialité et sécurité →
Réseau local. Le lanceur natif reste stable entre les mises à jour de l’agent.
L’installateur le prépare avant de consommer le jeton. Aucun réglage de confidentialité
global du Mac n’est désactivé.
