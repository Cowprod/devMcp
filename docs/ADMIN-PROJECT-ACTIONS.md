# Administration des actions des projets

## Sources de vérité

- **Local** : `/etc/devmcp/global.php` et les actions partagées validées par `ProjectRegistry` gouvernent l'exécution. Le service `devmcp` publie un instantané **descriptif** vers l'administration HTTPS ; le processus PHP-FPM ne lit pas la configuration privée.
- **Distribué** : les affectations et l'état de la machine sont enregistrés dans MySQL. Les cinq actions de consultation distante restent limitées par `remote-job-policy.php`, puis par les autorisations propres à l'agent. « Éligible côté registre » ne signifie pas « autorisé par l'agent ».
- Les actions distantes de modification du workspace et les permissions ADB restent hors de ce parcours d'administration. La PR pilote d'écriture distante ne constitue pas une autorisation de production.

## Instantané des actions locales

Le schéma v1 conserve `actions` (liste des identifiants) et ajoute, de manière facultative, `action_details`, indexé par identifiant :

- `description` : texte descriptif, limité à 255 octets ;
- `sync_allowed` : booléen, indiquant si l'appel synchrone est autorisé ;
- `timeout_seconds` : entier borné entre 1 et 86400.

La lecture accepte l'ancien instantané dépourvu de `action_details`. Elle refuse les champs supplémentaires par action, les clés inconnues et les types incorrects. Elle ne restitue pas les champs inattendus au navigateur. Ni commandes `argv`, ni répertoires d'exécution `cwd`, ni valeurs d'énumérations de paramètres ne doivent être publiés.

## Frontière de modification

L'édition des métadonnées d'un projet dans le registre SQL ne modifie **jamais** ses actions exécutables. Un futur formulaire de création/modification/suppression d'action devra passer par un protocole privilégié, spécifique et audité, validé indépendamment par l'agent : approbation, portée machine/projet, contrôle des changements et possibilité de révocation. L'interface ne doit pas permettre d'accorder une nouvelle autorisation simplement en insérant ou en mettant à jour un enregistrement MySQL.

Le formulaire de lecture distante ne constitue pas un éditeur de politique. Aucune capacité d'écriture sur les agents n'est activée par les informations descriptives de cette fonctionnalité.

## Suivi des actions distantes depuis la fiche Projet

La fiche **Projets** affiche, pour chaque affectation, jusqu'à cinq demandes
parmi les 100 derniers jobs de consultation distante du registre, avec action,
état (`queued`, `claimed`, `succeeded`, `failed`, `expired`), date UTC et
référence courte. Après une mise en file réussie, le navigateur revient à la
fiche du projet correspondant plutôt qu'au tableau de bord.

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
