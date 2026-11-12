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
Le projet/schéma doit être versionné et directement compilable par Xcode. La préparation Cordova est disponible avec `kind=cordova` (ci-dessous). Les
étapes propres à Flutter ou React Native ne sont pas générées implicitement.
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

## Projets Cordova

L’action reste `ios.build`. Dans `.devmcp/ios.json`, indiquer explicitement :

```json
{"kind":"cordova","path":".","scheme":"App","configuration":"Release","web_build_script":"build:web","verify_script":"verify:ios"}
```

`path` désigne le dossier Cordova dans le dépôt (`.` ou, par exemple, `app`).
`scheme` est le schéma Xcode généré par la version de cordova-ios du projet :
Cordova iOS 8 utilise `App`. Le runner exige l’unique `.xcworkspace` généré par
la préparation, sans choisir un projet différent en cas d’échec.

Versionner `config.xml`, `package.json`, `package-lock.json`, `www/`, les hooks,
les scripts de vérification et les plugins locaux. Déclarer `cordova` et
`cordova-ios` dans les dépendances npm, et `ios` dans `cordova.platforms`.
Les plateformes, plugins npm et dépendances sont restaurés à partir de ces
sources. Ne pas versionner `node_modules/`, `plugins/` ni `platforms/ios/`.

Le build copie le dépôt dans son propre dossier de travail, préserve les modes
exécutables et exécute `npm ci --include=dev --engine-strict`, l’éventuel script
`web_build_script`, puis `cordova prepare ios` avec le CLI local verrouillé.
Les hooks Cordova restent actifs. L’éventuel `verify_script` s’exécute après la
préparation : son échec arrête le build avant Xcode. Les deux noms de scripts
réfèrent à des entrées de `package.json` ; ils sont facultatifs.

Node et npm viennent de l’installation de l’agent. Le PATH est fourni
explicitement, sans dépendre d’un profil de terminal. Si CocoaPods manque,
l’agent l’installe avec le Homebrew de ce Mac. Une incompatibilité de versions,
un conflit de plugins ou un hook en erreur reste une erreur explicite, sans
substitution de version ni compilation d’une ancienne plateforme générée.

Xcode compile ensuite le workspace, puis la signature et la livraison HTTPS
suivent le même parcours que les projets natifs. Les sources Git et les autres
plateformes du dépôt ne sont pas modifiées par le runner. Les actions Android
existantes, notamment la préparation MultiCam, gardent leur fonctionnement.
L’exemple `examples/cordova-ios-smoke` valide le script web, un hook
`after_prepare`, le plugin natif Device et une compilation réelle.
