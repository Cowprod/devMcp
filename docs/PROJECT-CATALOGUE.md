# Catalogue public live des projets

Le projet PHP `Cowprod/devmcp-projects` appelle le lecteur borné
`bin/devmcp-project-catalogue --live` **à chaque requête HTTP**. Le lecteur utilise
`RuntimeFactory` et la même collecte SQL que le tool MCP `project_list` : projets
locaux, projets gérés sur les agents distants, projets historiques et inscriptions
non affectées. Il ne lit aucun export périodique ou instantané statique.

`PublicProjectCatalogue` ne transmet que les métadonnées d’affichage du schéma
JSON version 1. Les URL distantes viennent des métadonnées des agents ; les dates
HTTP distantes restent celles de leurs véritables observations. Les probes des
projets locaux sont effectuées pour les GET. Pour les HEAD de supervision,
`--metadata-only` relit le même registre sans lancer d’autres probes HTTP, ce qui
évite les appels récursifs au catalogue lui-même. Le projet HTTP appelant,
identifié par l’environnement du service, est exclu des probes ; sa requête
HTTP courante confirme elle-même sa disponibilité.

Le lecteur retourne une erreur si le registre est indisponible. Le site retourne
alors HTTP 503. La page ouverte interroge le même endpoint toutes les cinq secondes,
sans requêtes simultanées. Une erreur est affichée et ne conserve pas une ancienne
liste comme si elle était courante.

L’export périodique `projects.json` reste propre à l’administration historique ;
il ne sert pas de source pour ce catalogue. Le précédent `catalogue.json` n’est
plus produit ni consommé.
