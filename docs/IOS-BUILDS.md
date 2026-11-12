# Builds iOS avec l’agent macOS

Un Mac inscrit dans devMcp conserve les mêmes capacités d’hébergement statique,
PHP et Node que les agents Linux. Il expose également `ios.capabilities` et
`ios.build`, par `action_start` sur un projet affecté à ce Mac.

## Préparation d’un nouveau Mac

L’installation de l’agent prépare ses dépendances PHP/Node. Pour iOS, installer
séparément **Xcode complet**, le lancer une fois et terminer son installation,
puis sélectionner sa version avec `xcode-select` si nécessaire. Les Command Line
Tools seuls ne suffisent pas. Aucune présence préalable d’Xcode, de SDK ou de
certificat n’est supposée. `ios.capabilities` indique les outils effectivement
trouvés ; les erreurs de compilation restent explicites.

Dans Xcode → Settings → Accounts, connecter le compte développeur puis créer
un certificat **Apple Development** dans l’équipe choisie. La clé privée reste
sur le Mac. Une première signature peut demander l’autorisation d’accès au
trousseau dans sa session graphique. Les appareils doivent déjà être enregistrés
dans l’équipe Apple ; l’agent ne les enregistre pas automatiquement.

## Configuration dans le dépôt

Ajouter `.devmcp/ios.json` :

```json
{"kind":"project","path":"App.xcodeproj","scheme":"App","configuration":"Release"}
```

Pour un workspace, utiliser `"kind":"workspace"` et le chemin `.xcworkspace`.
Le projet/schéma doit être versionné et directement compilable par Xcode. Les
étapes propres à Cordova, Flutter ou React Native ne sont pas générées implicitement.
Les limites habituelles du paquet de sources devMcp restent applicables ; ne pas
versionner DerivedData, les IPA ou les archives Xcode. Les dépendances Swift
Package Manager sont résolues par Xcode. L’exemple `examples/ios-smoke` est une
petite app SwiftUI directement compilable.

## Appels MCP

Après `project_create` sur le Mac et confirmation du job de création :

```json
{"project":"mon-app","action":"ios.capabilities"}
```

Build simulateur explicitement non signé :

```json
{"project":"mon-app","action":"ios.build","arguments":{"target":"simulator","commit":"<SHA Git complet>"}}
```

IPA pour des appareils enregistrés :

```json
{"project":"mon-app","action":"ios.build","arguments":{"target":"device","commit":"<SHA Git complet>","team_id":"<Team ID Apple de 10 caractères>","method":"debugging"}}
```

`team_id` peut aussi être fixé dans `ios.json`. Le mode d’export est `debugging`
par défaut ; `release-testing` demande les certificats/profils de distribution
appropriés. Aucun échec de signature ne déclenche un build simulateur de secours.

Suivre le job avec `job_status` / `job_output`. Le Mac envoie des signaux de vie
pendant Xcode. Les sorties de compilation sont conservées sous
`ios-builds/<job>/build.log` dans son workspace ; la fin du journal figure dans
le résultat ou l’erreur. Les builds sont séparés des sources et du site hébergé.
Un même job terminé réutilise son résultat ; un build interrompu exige un nouveau
job pour éviter de présenter une archive incomplète comme terminée.

## Livraison HTTPS et installation

Le résultat contient la taille, le SHA-256, le type de build, `download_url` et
`build_url`. L’agent transmet l’archive par blocs authentifiés sur le protocole
HTTPS existant. Le serveur vérifie la taille et le SHA-256 avant de publier un
lien. Limite d’un artefact : 256 Mio. L’administration affiche les derniers builds
dans l’onglet Projets. Le lien donne accès uniquement au build correspondant.

Un build simulateur produit un ZIP pour le simulateur, jamais une IPA présentée
comme installable sur un téléphone. Un build appareil produit l’IPA signée et
une page proposant le manifeste d’installation `itms-services`.

L’iPhone/iPad doit être connecté au réseau ZeroTier, faire confiance au certificat
HTTPS du serveur central et, pour une version de développement, avoir le mode
développeur activé. Utiliser une IP ZeroTier en HTTPS est possible avec un certificat
contenant cette IP ; le certificat du lab doit être installé puis explicitement
approuvé sur chaque appareil. La génération d’une IPA ne prouve pas à elle seule
que son installation et son lancement sur un appareil physique ont été validés.

Le serveur HTTPS utilise un certificat de serveur distinct de la racine installée
sur les appareils : SAN de type IP, `extendedKeyUsage=serverAuth`, clé RSA 3072 bits
et chaîne signée par la racine existante. La migration automatique préserve le
fichier `/devmcp-ca.crt` octet pour octet ainsi que les empreintes d’enrôlement.
Elle sauvegarde la configuration nginx, la valide avant rechargement et conserve
un retour arrière. Les agents déjà enrôlés et les profils iPhone existants
continuent d’utiliser la même racine.
