# Création de projet depuis une conversation : première tranche

Statut : **aperçu non mutateur**. Référence : issue #59.

## Outil MCP

\`project_create_preview\` prend sept champs obligatoires :

| Champ | Exemple | Règle |
| --- | --- | --- |
| \`project_id\` | \`mytrackme\` | Identifiant canonique, minuscules, 1 à 64 caractères |
| \`description\` | \`Application métier\` | 1 à 255 octets |
| \`github_repository\` | \`Cowprod/mytrackme\` | Slug GitHub \`owner/repo\`, **pas** une URL |
| \`repository_mode\` | \`create\` ou \`attach\` | Créer un dépôt privé ou rattacher l'existant |
| \`target\` | \`local\` ou \`remote\` | Local = hôte du serveur MCP, pas le poste de l'utilisateur |
| \`machine\` | \`local\` ou \`agent-a\` | Hôte local, ou nom/identifiant d'une machine enregistrée |
| \`workspace_path\` | \`/srv/devmcp/workspaces/mytrackme\` | Chemin absolu canonique demandé ; la politique reste à vérifier |

Exemple de demande : « Prépare le plan pour \`Cowprod/mytrackme\` (création privée), sur \`agent-a\`, dans \`/srv/projects/mytrackme\`. »

## Ce que vérifie ce premier appel

- Les formats de nom, description, dépôt, mode, cible et chemin. Les segments \`.\`, \`..\`, les doubles séparateurs et les chemins relatifs sont rejetés.
- Les collisions avec les projets locaux actifs, et avec les projets enregistrés au central même s'ils n'ont aucune affectation activée.
- La correspondance exacte avec **une seule** machine distante dans le registre, ainsi que son statut. Le registre distribué est obligatoire pour une cible distante.
- Pour la cible locale, seule la machine \`local\` ou le véritable hostname du serveur MCP est acceptée.

Le résultat indique \`state=preview_only\`. Il affiche \`creation_authorized=false\`, \`workspace_verified=false\`, \`project_created=false\`, \`deployment_enabled=false\` et \`actions_authorized=false\`.

**Ce n'est pas un préflight concluant** : aucune requête vers l'API GitHub, aucun contrôle du chemin réel ou de la politique de l'agent, aucune requête SQL d'écriture, aucune commande ni création de fichier n'est effectuée. Si le registre distribué n'est pas disponible pour une cible locale, la vérification des collisions distantes reste explicitement indéterminée (\`null\`).

## Étapes suivantes, non autorisées par cette tranche

1. Action dédiée à la création de dépôt par l'API GitHub officielle, avec identité explicitement autorisée, propriétaire et visibilité privée imposés, refus des collisions et protection contre les doublons. Le connecteur GitHub disponible n'expose pas la création de dépôt.
2. Enregistrement SQL central idempotent et audité, affectation créée **désactivée**. Ne jamais confondre fiche SQL avec projet local exécutable.
3. Provisionnement du workspace sur la machine retenue par action spécifique approuvée par sa politique locale. Contrôler le chemin réel, les symlinks et les collisions avant toute écriture. Aucun \`argv\` ni shell arbitraire reçu via MCP.
4. Validation séparée des actions et permissions (aucune activation implicite, aucune utilisation de la PR #38 comme autorisation de production).

Les quatre workflows GitHub restent obligatoires avant fusion. Aucune donnée MultiCam ne doit être modifiée.
