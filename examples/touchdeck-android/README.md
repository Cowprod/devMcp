# Compteur TouchDeck Android

Un nouveau projet local devMcp : backend Node et app Cordova Android dans le même dépôt.

1. Créer le projet avec `project_create` sur le serveur central.
2. Synchroniser le commit exact avec `workspace.sync`, puis `project.deploy` (`runtime=node`).
3. Appeler `android.build` avec ce même commit.

La configuration Android choisit explicitement `toolchain=machine` : Node, Java 17, SDK 36 et Gradle du profil serveur. Le backend actif doit déclarer le même commit ; son URL est injectée dans la copie de compilation. Aucun chemin propre au Mac mini n’est utilisé.

Le résultat MCP contient les liens HTTPS de l’APK signé. Le backend propose également les liens sur `/downloads`. Le compteur persiste dans `DATA_DIR`. L’accès à l’API nécessite ZeroTier ; l’application est destinée aux tests du lab.
