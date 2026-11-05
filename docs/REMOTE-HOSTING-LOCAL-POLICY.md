# Hébergement distant — lecture de politique locale (phase 2F, inerte)

La classe `RemoteHostingLocalPolicy` prépare le futur exécuteur privilégié.
Elle n'est **pas** raccordée aux jobs distants, au timer de l'agent ni à
systemd. Elle ne crée aucun répertoire, ne lance aucun service et ne
modifie aucune interface, route, règle NAT ou règle de pare-feu.

La seule source autorisée pour la politique est
`/etc/devmcp/hosting-policy.json`. Le client MCP ne peut fournir ni
chemin, ni contenu JSON, ni commande locale. Le lecteur vérifie que
`/etc`, `/etc/devmcp` et le fichier sont des chemins réels (pas des
symlinks), que leurs propriétaires sont root et que les dossiers ne
sont pas modifiables par le groupe ou par d'autres utilisateurs.
Le fichier doit être régulier, root-only (`0600` ou `0400`), non
exécutable et limité à 64 KiB.

Le JSON utilise exactement les champs déjà définis pour
`RemoteHostingReleasePlan::preview()` : version, état enabled,
identité de machine, racine fixe, IP privée et déploiements explicitement
autorisés par port, runtime, dépôt et révision minimale. Une demande
distante ne comporte que l'identité de machine, le déploiement, le projet
et un SHA Git : aucune commande, option systemd ni adresse réseau
ne peut y figurer. Les plans produits portent toujours
`ready_to_execute=false`.

**Séparation des responsabilités :** ceci vérifie seulement la
provenance filesystem et le contrat JSON. Le futur exécuteur devra
également vérifier l'identité réellement enrôlée de l'agent, la preuve
Git récente, le propriétaire de l'interface ZeroTier, le verrou
de déploiement, la disponibilité du port, la provenance de la release,
les permissions du workspace, les tests HTTP/WS depuis un pair et
le rollback. Une politique JSON valide n'autorise aucune exécution.

La politique n'est pas créée ou activée sur `case` par cette PR. Les
tests exercent le parseur pur sans modifier `/etc` ni exiger root.
