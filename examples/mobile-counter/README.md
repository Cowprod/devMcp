# Compteur Lab

Un dépôt et un projet devMcp, affectés au Mini : backend Node sans dépendances et application Cordova dans `mobile/`. Les versions iOS et Android utilisent le même compteur persistant dans `DATA_DIR/counter.json`.

Déployer le backend avec `project.deploy` (`runtime=node`, commit exact), puis demander `ios.build` (device, équipe Listee `FB3A74YU84`) et `android.build` avec ce même commit. Le runner injecte l’URL réelle du backend dans la copie de build ; il refuse un backend absent ou d’une autre révision. Le dépôt n’est pas réécrit et aucun port n’est inventé.

La page `/downloads` du backend liste les liens HTTPS publiés par devMcp après chaque build. L’IPA utilise le manifeste iOS existant ; l’APK est signé en mode debug pour les tests. Le téléphone doit rejoindre ZeroTier et autoriser le réseau local. Pour iOS, conserver la confiance au certificat HTTPS du lab et le mode développeur.

Le backend de ce test utilise HTTP sur l’IP ZeroTier du Mini. Les accès HTTP de l’app sont explicitement configurés pour ce lab ; les téléchargements d’IPA/APK restent en HTTPS. La configuration Android indique explicitement Java 17, SDK 36/build-tools 36.0.0 et le Gradle installé sur cette machine. Aucun remplacement automatique de version n’est utilisé.
