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
identité de machine, racine fixe, IP privée, identifiant ZeroTier
`zerotier_network_id` (exactement 16 caractères hexadécimaux minuscules) et déploiements explicitement
autorisés par port, runtime, dépôt et révision minimale. Une demande
distante ne comporte que l'identité de machine, le déploiement, le projet
et un SHA Git : aucune commande, option systemd ni adresse réseau
ne peut y figurer. Les plans produits portent toujours
`ready_to_execute=false`.

**Liaison à l'enrôlement (phase 2G) :** `preview()` lit en plus
`/etc/devmcp/agent.php`, après contrôle des métadonnées du fichier :
régulier, propriétaire root, non modifiable par le groupe/autres, non
lisible par les autres, non exécutable et taille limitée. Le mode `0640`
installé par `install-agent.sh` reste autorisé afin que le groupe
`devmcp-agent` puisse lire son identité, mais jamais la modifier.
L'identifiant `machine_id` lu localement doit correspondre à celui
de la politique **et** à celui de la demande distante. Aucun champ
fourni par le client n'est accepté comme preuve d'identité enrôlée.

La méthode `previewJsonForEnrolledMachine()` permet les tests purs
sur des identifiants fictifs ; son troisième argument n'est pas une
attestation. **Seule `preview()` lit et vérifie la source locale.**
Un résultat reste non exécutable (`ready_to_execute=false`) et ne
garantit ni l'authentification du job sur le serveur, ni le lancement
de services, ni la vérification du réseau ou du dépôt.

**Séparation des responsabilités :** ceci vérifie seulement la
provenance filesystem et le contrat JSON. Le futur exécuteur devra
également vérifier l'identité réellement enrôlée de l'agent, la preuve
Git récente, l'identifiant du réseau ZeroTier épinglé, le propriétaire de l'interface, le verrou
de déploiement, la disponibilité du port, la provenance de la release,
les permissions du workspace, les tests HTTP/WS depuis un pair et
le rollback. Une politique JSON valide n'autorise aucune exécution.

La politique n'est pas créée ou activée sur `case` par cette PR. Les
tests exercent le parseur pur sans modifier `/etc` ni exiger root.

**Phase 2I — réseau autorisé :** la politique JSON root-only doit maintenant
contenir un `zerotier_network_id` obligatoire. Les anciennes politiques
sans ce champ sont rejetées fermement ; aucun identifiant transmis par
la requête MCP n'est accepté. La classe
`RemoteHostingZeroTierNetworkPin::inspect($request)` recharge la politique
depuis `/etc/devmcp/hosting-policy.json` et compare l'ID au réseau
effectivement déclaré par le démon local. Le contrôle reste inerte.
