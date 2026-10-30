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

## Création du dépôt GitHub — implémentation opt-in

L'outil MCP \`github_repository_create\` prend \`repository\` au format \`Cowprod/nom\` et \`confirmation=CREATE_PRIVATE_REPOSITORY\`. Il est **désactivé par défaut**. Sans activation explicite dans la configuration centrale, aucun appel HTTP n'est émis.

La configuration root-controlled \`/etc/devmcp/global.php\` doit, après validation de l'opérateur, contenir :

~~~php
'github_repository_creation' => [
    'enabled' => true,
    'organization' => 'Cowprod',
    'token_file' => '/etc/devmcp/github-repository-token',
],
~~~

Le fichier de jeton est séparé du dépôt Git, non accessible à l'interface Web et lisible uniquement par son propriétaire (permissions 0600 ou plus strictes). Le serveur exige un fichier régulier, canonique, non lié et possédé par root ou l'utilisateur courant. Ce chemin unique est fixé dans le code et ne peut pas être choisi depuis une conversation.

L'identité GitHub doit être autorisée pour la **création de dépôts** dans l'organisation, idéalement avec la permission *Repository creation: write* d'un GitHub App ou d'un jeton à droits limités. Ne pas lui donner d'accès global aux contenus de tous les dépôts. L'application effectue uniquement \`POST https://api.github.com/orgs/Cowprod/repos\` avec visibilité **private**, \`auto_init=true\`, un corps de requête borné et un transport HTTPS vérifié sans redirection. Toute réponse non 201 ou non conforme est rejetée ; un HTTP 422 (dépôt existant) n'est jamais déclaré comme une création réussie.

Le résultat \`github_created\` signifie seulement que l'API a confirmé la création du dépôt privé. **Il ne signifie pas** que le projet a été enregistré dans SQL, que le workspace existe ni qu'un agent a reçu de nouvelles autorisations. Chaque tentative est auditée avec le nom du dépôt et le résultat, sans jeton, corps HTTP ou contenu de fichier.

Les opérations de rattachement au registre, création de workspace, activation d'actions et gestion des états partiels restent séparées.

## Étapes suivantes, non autorisées par cette tranche

1. **Implémenté mais OFF** : création de dépôt privé via l'API GitHub, nécessitant une identité explicitement autorisée. Restent à ajouter une vérification préalable de l'existence et une réconciliation robuste des réponses ambiguës après incident réseau.
2. Enregistrement SQL central idempotent et audité, affectation créée **désactivée**. Ne jamais confondre fiche SQL avec projet local exécutable.
3. Provisionnement du workspace sur la machine retenue par action spécifique approuvée par sa politique locale. Contrôler le chemin réel, les symlinks et les collisions avant toute écriture. Aucun \`argv\` ni shell arbitraire reçu via MCP.
4. Validation séparée des actions et permissions (aucune activation implicite, aucune utilisation de la PR #38 comme autorisation de production).

Les quatre workflows GitHub restent obligatoires avant fusion. Aucune donnée MultiCam ne doit être modifiée.
