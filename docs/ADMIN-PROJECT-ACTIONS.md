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
