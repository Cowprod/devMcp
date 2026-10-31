# Administration des actions des projets

## Sources de vérité

- **Local** : `/etc/devmcp/global.php` et les actions partagées validées par `ProjectRegistry` gouvernent l'exécution. Le service `devmcp` publie un instantané **descriptif** vers l'administration HTTPS ; le processus PHP-FPM ne lit pas la configuration privée.
- **Distribué** : les affectations et l'état de la machine sont enregistrés dans MySQL. Les cinq actions de consultation distante restent limitées par `remote-job-policy.php`, puis par les autorisations propres à l'agent. « Éligible côté registre » ne signifie pas « autorisé par l'agent ».
- Les actions distantes de modification du workspace et les permissions ADB restent hors de ce parcours d'administration. La PR pilote d'écriture distante ne constitue pas une autorisation de production.

## Instantané des actions locales

Le schéma v1 conserve `actions` (liste des identifiants) et ajoute, de manière facultative, `action_details`, indexé par identifiant :

- `description` : texte descriptif, limité à 255 octets ;
- `sync_allowed` : booléen, indiquant si l'appel synchrone est autorisé ;
- `timeout_seconds` : entier borné entre 1 et 86400 ;
- `parameters` : liste descriptive facultative (maximum 64), avec `id`, `type` et `required=true`. Les types autorisés sont `git_sha`, `android_serial`, `serial_device`, `relative_path`, `enum` et `integer`.

La fiche **Projets** affiche le nom et le type des paramètres requis pour les actions locales. Il s'agit d'un inventaire **en lecture seule**, pas d'un formulaire d'exécution ni d'une habilitation. L'instantané ne publie ni descriptions privées de paramètres, ni choix des énumérations, ni bornes, ni commande.

La lecture accepte les anciens instantanés dépourvus de `action_details` ou de `parameters`. Elle refuse les champs supplémentaires par action ou paramètre, les identifiants dupliqués, les clés inconnues et les types incorrects. Elle ne restitue pas les champs inattendus au navigateur. Ni commandes `argv`, ni répertoires d'exécution `cwd`, ni valeurs d'énumérations de paramètres ne doivent être publiés.

## Frontière de modification

L'édition des métadonnées d'un projet dans le registre SQL ne modifie **jamais** ses actions exécutables. Un futur formulaire de création/modification/suppression d'action devra passer par un protocole privilégié, spécifique et audité, validé indépendamment par l'agent : approbation, portée machine/projet, contrôle des changements et possibilité de révocation. L'interface ne doit pas permettre d'accorder une nouvelle autorisation simplement en insérant ou en mettant à jour un enregistrement MySQL.

Le formulaire de lecture distante ne constitue pas un éditeur de politique. Aucune capacité d'écriture sur les agents n'est activée par les informations descriptives de cette fonctionnalité.

La fiche **Projets** présente pour chaque affectation un catalogue repliable des cinq actions distantes de consultation, avec leur description et les paramètres `path` (chemin relatif) et `filter` (énumération) lorsqu'ils s'appliquent. Il reste consultable pour les affectations désactivées et les machines non approuvées, **sans rendre le formulaire de mise en file disponible**. Le formulaire historique des affectations et celui des projets utilisent la même liste descriptive centrale `remoteReadonlyActionCatalog()` ; seul `remoteJobAllowed()` puis la politique de l'agent déterminent ce qui est accepté. Ce catalogue n'affiche aucune permission effective et ne donne accès ni aux actions d'écriture ni aux politiques privées des machines.

Les options du filtre de `workspace.find` (`all`, `php`, `js`, `json`, `md`) proviennent maintenant du même catalogue PHP que la validation de la file. Les formulaires de la fiche Projet et de l'affectation utilisent également le même mécanisme pour afficher les champs `path` et `filter` applicables à l'action sélectionnée. Il s'agit d'une synchronisation d'interface, pas d'une extension des filtres ou des autorisations : la requête reste contrôlée par `remoteJobAllowed()` puis par l'agent.

## Suivi des actions distantes depuis la fiche Projet

La fiche **Projets** affiche, pour chaque affectation, jusqu'à cinq demandes
parmi les 100 derniers jobs de consultation distante du registre, avec action,
état (`queued`, `claimed`, `succeeded`, `failed`, `expired`), date UTC et
référence courte. Après une mise en file réussie, le navigateur revient à la
fiche du projet correspondant plutôt qu'au tableau de bord.

La fiche **Projets** associe aussi à chaque action du catalogue distant l'état de sa **dernière demande visible** sur cette affectation, en réutilisant les mêmes métadonnées (aucune nouvelle requête SQL ni lecture des résultats de job). Les cinq actions restent affichées, même si aucune demande ne figure parmi les 100 dernières du registre. Le statut est recalculé lors du rafraîchissement à 15 secondes ; en cas d'échec, il est explicitement marqué comme potentiellement périmé. Le succès d'une demande passée n'est **jamais** présenté comme une autorisation d'exécution actuelle : l'acceptation future dépend toujours des validations serveur et agent.

Ce suivi lit seulement les métadonnées SQL (`dm_remote_readonly_job` et
`dm_deployment`). Il ne charge **ni les arguments**, **ni `result_json`** :
un résultat de `workspace.read` peut contenir des données sensibles.
`succeeded` signifie que la file a enregistré un succès rapporté par l'agent,
et ne constitue pas une validation des permissions pour des demandes futures.

L'onglet **Projets** actualise ces seuls états toutes les 15 secondes tant qu'il
est visible, sans recharger la page ni effacer les paramètres d'une action en
cours de préparation. La route GET `/?remote_jobs=1` exige le même HTTPS et
l'authentification du proxy que l'interface ; elle utilise le compte SQL
**lecture seule**, renvoie au plus 100 enregistrements et ne projette que
`id`, `deployment_id`, `project_id`, `action_name`, `state`,
`created_at` et `finished_at`. Aucun contenu de fichier, argument,
résultat ou politique d'agent n'est exposé. La réponse est non mise en cache.
Si le rafraîchissement échoue, l'interface garde les derniers états mais
les signale comme potentiellement anciens. Il ne s'agit pas d'un flux
temps réel ni d'une preuve d'exécution autorisée.

Si la migration 005 ou le droit SQL SELECT manque, l'historique affiche un
avertissement sans rendre les autres écrans indisponibles. Le nombre de demandes
affichées est borné ; l'absence de demande n'implique pas l'absence d'anciens
jobs. Aucun changement de politique, aucune permission ajoutée, aucune
mutation du workspace distant ne découle de ce suivi.

## Recherche dans les actions d'un projet

La fiche **Projets** propose un filtre local (nom ou description) commun aux entrées
locales déclarées et aux cinq actions distantes présentées par affectation.
Lorsqu'une action distante correspond, son catalogue est déplié ; les catalogues
sans correspondance sont masqués seulement le temps de la recherche. Les formulaires
de demande et les historiques ne sont pas modifiés par ce filtre.

Les identifiants d'actions locales sont désormais transmis au navigateur sous
forme d'un tableau JSON échappé dans `data-local-actions`, provenant de
`LocalProjectSnapshot::read()` ; le navigateur ne reconstruit plus les identifiants
en découpant la représentation textuelle des cellules du tableau. Les données
publiées restent strictement descriptives : aucune commande, aucun argument
secret, aucune valeur d'énumération privilégiée.

La recherche inclut désormais, en plus du nom et de la description, les **identifiants et types** des paramètres affichés : par exemple `serial`, `relative_path` ou `integer` côté local, et `path`/`filter` côté distant. Les valeurs autorisées d'énumération, arguments réels, commandes et informations privées des politiques ne sont pas indexés. Le filtre accepte plusieurs mots-clés séparés par des espaces : **tous doivent correspondre**, quel que soit leur ordre. La recherche ignore la casse et les accents (par exemple `echec` retrouve `échec`). Le filtre fonctionne également avec le choix d'origine Locales/Distantes/Toutes.

Le filtrage est exclusivement visuel et ne constitue pas un contrôle de droits.
La création de demandes distantes passe toujours par le même validateur serveur
et la politique autonome de chaque agent ; aucune action locale n'est rendue
exécutable depuis la fiche Projets.

## Filtrage par origine

Depuis la fiche **Projets**, le catalogue peut être limité aux actions **Locales**, **Distantes** ou **Toutes**, tout en conservant la recherche textuelle commune. Le compteur de correspondances applique les deux critères. Le filtre masque également les titres des groupes sans correspondance et les cartes d'affectation dont aucune action distante ne correspond, y compris leurs formulaires et historiques visibles. Ces éléments restent dans le DOM : revenir à « Toutes » ou effacer la recherche les réaffiche **sans perdre les champs déjà saisis** ni les états des demandes. Aucun job n'est lancé, annulé ou modifié par le filtrage. Il s'agit uniquement d'un filtre du catalogue et non d'un mécanisme d'activation ou de désactivation d'actions. Les demandes passent toujours par la validation serveur et la politique indépendante des agents.

## Préparer une action distante depuis son catalogue

Dans la fiche **Projets**, le bouton **Préparer** de chaque action distante de consultation sélectionne cette action dans le formulaire de mise en file de son affectation. Les champs `path` et `filter` sont affichés selon le catalogue central, sans remplacer les valeurs déjà saisies. Le formulaire devient visible et le champ pertinent reçoit le focus. **Aucune demande n'est envoyée à ce stade** : l'opérateur doit encore cliquer explicitement sur **Mettre en file**.

Ce bouton n'est créé que pour une affectation activée, une machine approuvée et une session possédant un jeton CSRF ; les catalogues des affectations inéligibles restent consultables sans bouton de préparation ni formulaire. Cette condition d'affichage ne constitue **pas** une garantie d'autorisation : les contrôles serveur (`remoteJobAllowed()`, état du registre) et la politique indépendante de l'agent sont toujours déterminants au moment de l'exécution. Les actions locales restent un inventaire non exécutable depuis le navigateur.
