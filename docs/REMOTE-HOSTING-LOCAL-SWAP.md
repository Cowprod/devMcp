# Hébergement caseRouter — publication atomique locale (étape exécutable, non déployée)

Cette étape ajoute deux **opérations réelles sur le système de fichiers**, réservées
au processus local root, sans action MCP exposée :

- `RemoteHostingReleaseSwap::switch(request, operationId)` publie, par lien
  symbolique **relatif** et `rename()` atomique, une release déjà vérifiée sous
  `/srv/devmcp-hosting/<project>/releases/<sha>`. Il écrit et synchronise
  `.activation-journal` **avant** la bascule puis après celle-ci.
- `RemoteHostingReleaseSwap::rollback(request, operationId)` restaure le lien
  précédent, ou le retire pour un premier déploiement échoué. Le journal demeure
  présent et bloque toute autre activation tant que l'orchestrateur n'a pas
  constaté et clôturé la récupération.

Les deux méthodes exigent l'identité machine et le grant root-only de
`/etc/devmcp/hosting-policy.json`, un verrou `flock` unique partagé avec
l'installateur d'unités, des arbres de releases localement vérifiés (Git et
inodes), et un service applicatif **inactif**. Elles refusent les cibles
absolues, `../`, les symlinks `current` non root et les états de récupération
incompatibles. Aucune commande de route, NAT, pare-feu ou réseau n'est exécutée.

La version ne lance **ni** `systemctl start` **ni** `systemctl restart` et
n'autorise **pas** le service d'application : `ready_to_serve=false`. Ne pas
appeler directement `switch()` depuis un agent distant ou un contrôleur
central : seules les futures transactions privilégiées peuvent orchestrer
l'arrêt de l'ancien service, la bascule, le redémarrage, le health-check
**depuis un autre pair ZeroTier**, la validation durable et le rollback.

## Choix des sources d'exemple

Un correctif au manifeste Git résout une incompatibilité concrète : les trois
démonstrateurs de `Cowprod/devMcp` résident sous `examples/`, alors que
l'ancien validateur exigeait `public/index.*` et `server.js` à la racine.

Pour **ce dépôt précis**, les chemins de source sont fixés dans le code selon
le runtime approuvé par la politique locale :

| Runtime | Sous-arbre Git |
| --- | --- |
| `static` | `examples/remote-hosting-static` |
| `php` | `examples/remote-hosting-php` |
| `node` | `examples/remote-hosting-node` |

Le contenu du sous-arbre est lu dans le commit Git approuvé sous forme de chemins
**relatifs** ; toutes les règles de noms, modes, taille, origine, ancêtres et
inodes s'appliquent toujours. Les autres dépôts utilisent leur racine. Le
démonstrateur Node n'a pas de dépendances npm : `package.json` et `server.js` sont requis.

## Validation

```sh
php -l src/Project/RemoteHostingReleaseSwap.php
php -l src/Project/RemoteHostingGitTreeEvidence.php
php -d zend.assertions=1 -d assert.exception=1 tests/distributed/hosting-release-transaction-contract.php
```

L'exercice sur le lien et le journal n'est exécuté qu'en environnement de
test root isolé dans `/tmp`. Sur un runner non privilégié, le contrôle de
la table de sélection des sous-arbres reste exécuté et l'intégration root est
explicitement ignorée.

## Bloquants avant trois déploiements réels

1. Préparer matériellement les arbres de release immuables depuis le miroir
   Git approuvé, avec contrôle de l'origine et du rafraîchissement de main.
2. Câbler un exécuteur local root-only à l'agent distant **après** bootstrap
   explicite, sans commande shell arbitraire.
3. Intégrer `RemoteHostingSystemdControl` dans la même transaction et
   conserver le journal jusqu'aux contrôles d'état du service.
4. Ajouter la preuve de santé HTTP/WebSocket depuis **un pair ZeroTier
   indépendant**, la confirmation durable, le fencing anti-rejeu et la
   récupération automatique après crash.
5. Effectuer un déploiement physique contrôlé sur caseRouter après validation.

**État : 0/3 démonstrateurs déployés et vérifiés.** Cette modification ne
touche ni caseRouter ni les paramètres réseau.
