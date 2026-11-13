# Catalogue public des projets

`bin/devmcp-admin-projects-export` conserve l’instantané local `projects.json`
nécessaire à l’administration et publie séparément `catalogue.json` dans
`/var/lib/devmcp-admin-bridge/`. Ce second fichier reprend **la même liste que
le tool MCP `project_list`**, obtenue par `RuntimeFactory` et `ProjectTools`.
Le registre SQL distribué doit être disponible : aucune liste locale de secours
n’est publiée à sa place.

`PublicProjectCatalogue` ne conserve que les champs d’affichage du schéma JSON
version 1 : identité, description, machine, catégorie, observation HTTP et date,
état de provisionnement, URL web, dépôt GitHub et liste d’artefacts (actuellement
vide). Ni commandes, ni chemins de workspace, ni paramètres de connexion ne
traversent ce fichier. La publication est atomique, avec les mêmes permissions
0640 que le pont administratif existant.

Une URL distante est reprise des métadonnées de l’agent, jamais reconstruite
avec l’adresse de TouchDeck. Une réservation de port sans URL attestée ne crée
pas un lien distant. Les projets iOS sans backend web restent dans l’inventaire.
Les dates des observations HTTP restent celles des contrôles réels.

Le projet PHP `Cowprod/devmcp-projects` consomme uniquement cet export pour sa
page et son endpoint `?format=json`. Il refuse les exports âgés de plus de trois
minutes avec HTTP 503 ; il ne remplace pas une erreur par un instantané statique.
Déployer le moteur central et vérifier la publication de `catalogue.json` avant
de déployer ce consommateur.
