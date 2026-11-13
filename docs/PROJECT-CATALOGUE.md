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

## Projets mobiles : champs publics communs à MCP et au JSON

`project_list`, `project_describe` et le catalogue publient :

- `platforms` : objet `{ "web": true, "ios": true, "android": true }`. Les
  plateformes mobiles viennent des fichiers `.devmcp/ios.json` et
  `.devmcp/android.json`, jamais des seules capacités de la machine.
- `mobile_configuration` : déclaration connue ou non, révision source distante
  et éventuelles erreurs de configuration. Une valeur de plateforme `null`
  signifie inconnue/invalide, pas une plateforme absente.
- `mobile_builds.ios` et `mobile_builds.android` : dernier job, état
  (`not_built`, `queued`, `running`, `succeeded`, `failed`, `cancelled`, `expired`),
  cible, version, révision, dates UTC et disponibilité du fichier.
- `artifacts` : uniquement les IPA/APK du dernier build réussi de leur plateforme
  et dont le téléchargement HTTPS répond réellement avec la taille attendue.
  Chaque entrée contient `platform`, `kind`, `job_id`, `version`, `bundle_id`,
  `revision`, `size`, `sha256`, `build_url`, `download_url`, `available`.

Les sources distantes sont lues dans le cache Git central au commit du dernier
paquet adressé à la machine actuellement affectée. Les états viennent directement
du registre des jobs distants ou des métadonnées des jobs locaux. Les anciens
jobs d’une machine précédemment affectée sont exclus. Les fichiers de configuration,
chemins de builds et journaux ne sont pas publiés. Les fichiers déjà compilés
sont reconnus sans rebuild ni nouvelle intervention sur les agents.

Les vérifications de téléchargement sont des HEAD parallèles, bornés et sans
redirection, vers les seuls liens émis sur l’origine HTTPS du serveur central.
Le certificat est vérifié avec la CA publique du lab installée dans le pont
administratif. Une vérification impossible donne `availability=unverified`,
un fichier retiré `missing` ; aucun lien n’est publié dans ces deux cas.
Un nouveau job en attente/en cours/échoué remplace l’ancien succès dans le
catalogue, conformément à la suppression des anciens fichiers au rebuild.
Une archive simulateur ne devient jamais un IPA installable.
