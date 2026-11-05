# Hébergement distant — phase 2D : activation et rollback (contrat inerte)

Ce lot définit un protocole de publication, **sans installateur ni action distante**.

`RemoteHostingActivationContract::draft()` reçoit exclusivement un plan provenant
de `RemoteHostingReleasePlan::preview()`, un identifiant d'opération hexadécimal
de 32 caractères et une éventuelle révision précédente de 40 caractères.

Le draft matérialise uniquement les chemins attendus :
`/srv/devmcp-hosting/<project>/releases/<sha>`,
`/srv/devmcp-hosting/<project>/current`, un verrou local et un journal.
Il n'écrit **aucun** de ces chemins. `ready_to_apply=false` est invariant.

Machine d'états prévue :

- `idle -> prepared` après préparation et validations locales ;
- `prepared -> activated` après bascule atomique contrôlée ;
- `activated -> verified` après tests de santé indépendants ;
- `verified -> committed` après validation durable ;
- `prepared -> rolled_back` par abandon ;
- `activated/verified -> rolled_back` après restauration validée.

Les transitions sont des **descriptions**, pas des autorisations. Elles restent
inertes et interdisent les chemins inverses ou les relectures naïves.

## Obligations du futur exécuteur privilégié

1. Lire la politique locale root-only et vérifier l'identité de machine.
2. Refuser le rejeu via un nonce persisté localement, un verrou exclusif par
   déploiement et un jeton de fencing monotone. Ne jamais accepter une
   confirmation d'état provenant exclusivement du serveur central.
3. Récupérer le commit depuis le dépôt HTTPS approuvé puis valider la preuve
   Git et les chemins canoniques sans symlink ni écriture par l'app.
4. Vérifier la possession de l'adresse ZeroTier et la réservation/occupation
   effective du port ; ne modifier aucune règle réseau.
5. Préparer une release immuable et l'unité systemd sous compte confiné.
6. Journaliser durablement avant et après toute bascule ; assurer la reprise
   après interruption, et restaurer la release précédente en cas d'échec.
7. Exiger un contrôle santé HTTP ou WebSocket depuis **un autre pair ZeroTier**
   avant de confirmer l'activation.
8. Rendre explicite le cas d'une installation initiale sans release précédente :
   l'échec doit revenir à un état sans service applicatif plutôt qu'à une
   ancienne cible inexistante.

Cette PR n'installe ni service, ni timer, et n'ouvre aucun port. Le canal distant
`caserouter` conserve exclusivement ses six actions de lecture seule.
L'auto-update de l'agent et le moteur d'exécution sécurisé demeurent des travaux
distincts, à valider avant toute installation sur `case`.
