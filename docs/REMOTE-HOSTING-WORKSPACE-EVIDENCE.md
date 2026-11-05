# Hébergement distant : vérification des parents de workspace (inerte)

`RemoteHostingWorkspaceEvidence::inspect($request)` vérifie exclusivement les
**trois parents fixes** de la future application :
`/srv`, `/srv/devmcp-hosting` et
`/srv/devmcp-hosting/<project_id>`. L'identifiant de projet est issu du plan
approuvé par la politique locale `/etc/devmcp/hosting-policy.json` et associé
à l'identité réellement enrôlée. Aucun chemin d'entrée n'est fourni par MCP.

Le contrôle lit `lstat()`, refuse les chemins absents, les liens
symboliques, les objets non-répertoires, les propriétaires non-root, les
permissions d'écriture groupe/autres, les répertoires à bits spéciaux et les
permissions empêchant root de traverser le chemin. Le chemin canonique est
épinglé sous `/srv/devmcp-hosting` et ne peut pas pointer vers
`/opt/caserouter` par l'intermédiaire de ces parents. La vérification est
en **lecture seule** : aucun `mkdir`, `chown`, `chmod`, montage,
service systemd, connexion réseau, route ou pare-feu.

Ce contrôle **n'est pas un moyen d'autoriser l'exécution** :
`ready_to_execute=false`. La vérification est instantanée, sensible
aux changements de métadonnées après `lstat()` (TOCTOU), et ne couvre pas
les sous-répertoires `releases/<sha>`, les hardlinks, les points de montage,
le contenu applicatif, les dépendances, le symlink `current` ni la preuve
d'ascendance Git. Le futur exécuteur local devra revalider l'intégralité
du chemin sans suivre de lien, conserver une identité de répertoire stable,
isoler les montages, vérifier la provenance des fichiers et maintenir un
verrou exclusif pendant l'activation et son éventuel rollback.

La méthode `check()` accepte des métadonnées de tests déterministes :
elle ne constitue **jamais** une preuve lorsque ses arguments viennent
d'une requête distante. Seule `inspect()` collecte des données locales
après vérification de la politique root-only.

Tests : `tests/Distributed/RemoteHostingWorkspaceEvidenceTest.php`, trois
runtimes statique/PHP/Node, refus des liens symboliques, propriétaire
non-root, modes ouverts, chemin trompeur, preuves manquantes ou falsifiées.
Aucune action distante nouvelle n'est accordée. Aucun provisionnement ni
hébergement n'est déclenché sur `case`.
