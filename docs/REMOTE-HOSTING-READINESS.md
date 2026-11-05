# Préflight d'hébergement sur agent distant — phase 1 (lecture seule)

## But

Préparer les démonstrateurs statique, PHP et Node/WebSocket sur `case` sans
transformer une politique de consultation en autorisation de déploiement.

Le nouvel identifiant `host.runtime` passe par la même file distante que
`git.head`, avec **arguments strictement vides**. Le serveur contrôle une
affectation active, puis l'agent exige toujours une entrée explicite dans
`/etc/devmcp/remote-readonly-policy.php` pour le `deployment_id` exact.
Il ne suffit jamais d'avoir mis à jour GitHub ou le catalogue central.

La sonde rend uniquement des valeurs bornées et non sensibles :

- `runtimes.static/php/node` : présence d'exécutables dans les chemins fixes
  `/usr/bin/python3`, `/usr/bin/php`, `/usr/bin/node` ;
- `systemd_available` : existence de `/run/systemd/system` ;
- `workspace_canonical` : correspondance du workspace autorisé ;
- `hosting_deployment_enabled=false` et
  `remote_execution_enabled=false` : constat de la phase actuelle ;
- `network_binding_policy=unconfigured`.

Il n'y a **aucune exécution de binaire**, aucun service démarré, aucune
lecture de configuration privée, aucun accès au pare-feu, aucune écriture
dans le workspace et aucun changement de routage.

## Mise en service

1. Valider les contrôles GitHub sur cette tranche, fusionner dans `main` et
   appliquer le mécanisme d'auto-update central.
2. Mettre à jour l'agent via le processus administrateur déjà approuvé
   (`upgrade-agent-readonly.sh`, commit exact) : cette étape n'est pas
   faisable par l'actuel MCP distant en lecture seule.
3. L'opérateur de la machine ne peut ajouter `host.runtime` à la liste
   locale des actions d'une affectation précise qu'après avoir vérifié
   l'identité, le chemin et les permissions existantes.
4. Interroger l'action en MCP. Le résultat ne garantit **pas** que l'hôte
   peut publier un site depuis une autre interface réseau.

## Étape suivante : provisionnement distant

Ce préflight **n'active pas** `workspace.sync`, `project.deploy`, SSH,
systemd, apt, sudo ou l'exécution de commandes arbitraires. Les trois
démonstrateurs restent à créer/déployer sur la machine cible.

La phase 2 doit fournir une voie de publication indépendante des fonctions
réseau de caseRouter, avec identité de dépôt épinglée, SHA Git exact,
workspace borné, port attribué par la machine, refus des symlinks et des
dépôts modifiés, droit local explicite par projet/runtime, rollback de
service et preuve HTTP depuis un pair ZeroTier. Aucun de ces privilèges ne
doit être déduit des métadonnées SQL du serveur central.
