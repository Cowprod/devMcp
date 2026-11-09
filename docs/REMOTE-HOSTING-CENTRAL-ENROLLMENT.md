# Enrôlement central des trois démonstrateurs caseRouter

**État : implémenté, non exécuté sur le registre réel.** Cette étape
prépare les trois IDs dont `bootstrap-lab` a besoin sur caseRouter.
Elle ne modifie jamais `Cowprod/caseRouter`, la configuration réseau,
les services du routeur ou les permissions root de l'agent.

## Approbation centrale distincte

Le registre central doit déjà contenir la machine **active et enrôlée**
(identifiant issu de `dm_machine` et au moins une identité d'agent
non révoquée, non expirée). Les migrations SQL 006 puis 007 doivent être
installées; l'opération vérifie **exactement** le type ENUM de l'action
`dm_remote_hosting_job.action_name` avant toute écriture.

L'administrateur central active séparément
`remote_hosting_queue_enabled=true` dans le fichier de configuration
privé `/etc/devmcp-admin/distributed.php`. **Ce nouveau code ne change
pas ce drapeau.** Ni la file des actions classiques, ni la politique locale
de l'agent caseRouter ne sont modifiées.

Seul l'administrateur root de la **machine centrale** peut invoquer le
CLI `bin/devmcp-hosting-lab-enroll --apply`. Il reçoit sur stdin un JSON
à clés exactes et un marqueur de consentement, par exemple :

```json
{"machine_id":"<ID de dm_machine actif, 32 hex>","approval":"ENROLL_CASE_HOSTING_LAB"}
```

Le binaire exige une configuration root-owned, une base active et une
autorisation explicite. Il crée dans **une seule transaction SQL** les
projets et déploiements suivants, sans en accepter d'autres :

| Runtime | Projet | Repository | Workspace | Port |
| --- | --- | --- | --- | --- |
| Static | `lab-static` | `https://github.com/Cowprod/devMcp.git` | `/srv/devmcp-hosting/lab-static` | 17601 |
| PHP | `lab-php` | idem | `/srv/devmcp-hosting/lab-php` | 17602 |
| Node/WS | `lab-node` | idem | `/srv/devmcp-hosting/lab-node` | 17603 |

Les IDs sont générés par le serveur central, pas fournis par le routeur,
et la réponse contient uniquement le `machine_id`, les trois
`deployment_ids` et les ports (aucun secret). Ils sont destinés à la
configuration **root-only** de `bootstrap-lab`.

Les objets existants sont acceptés seulement si dépôt, workspace et état
`enabled=1` correspondent exactement. Une affectation désactivée
n'est **jamais réactivée** par une relance; toute dérive entraîne un refus
et un rollback SQL total. L'opération est idempotente et auditée dans
`dm_audit_event` pour les nouvelles créations.

## Ordonnancement restant

1. Préparation centrale administrateur : migrations 006/007, opt-in
   serveur, enrôlement des trois déploiements.
2. Protéger la même clé HMAC sur le second pair ZeroTier et renseigner
   les IP réelles. Ne jamais transmettre cette clé dans la file ou les logs.
3. **Une seule intervention root sur caseRouter**, avec le consentement
   complet décrit dans `REMOTE-HOSTING-ONE-SHOT-BOOTSTRAP.md`.
4. Après opt-in local, demander `prepare-all`, `activate-initial`,
   vérifier HTTP/WS depuis le pair indépendant, puis faire le
   `commit-initial` signé; watchdog local en cas d'échec.

**Statut terrain : 0/3.** Aucune étape d'enrôlement central n'autorise,
à elle seule, un service à écouter ou une application à être considérée
comme déployée. Ne pas demander la commande root sur caseRouter avant
validation de l'ensemble des prérequis.
