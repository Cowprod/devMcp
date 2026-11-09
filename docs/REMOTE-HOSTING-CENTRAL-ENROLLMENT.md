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

### Initialisation centrale en une seule invocation root (nouvelle option)

Pour une instance où les migrations 006/007 ne sont **pas** encore appliquées,
la commande `bin/devmcp-hosting-lab-enroll --bootstrap` réalise les deux
migrations **puis** les trois affectations dans un même parcours root approuvé.
Le consentement JSON exact est :

```json
{"machine_id":"<32 hex de la machine active>","approval":"MIGRATE_AND_ENROLL_CASE_HOSTING_LAB","backup_confirmed":true}
```

Cette option exige un backup MySQL préalablement effectué et **attesté
explicitement**, les credentials DBA dédiés `migration_username` et
`migration_password` dans la configuration root du central, ainsi que
le drapeau root `remote_hosting_queue_enabled=true` déjà présent.
Elle ne modifie pas le drapeau. L'identité machine active et un enrôlement
non expiré sont revérifiés **avant** toute DDL.

Le migrateur prend un verrou MySQL fixe et accepte uniquement trois états
de schéma : absent (crée 006 puis applique 007), 006 exact (applique
uniquement 007), 007 exact (aucune DDL). Un schéma étranger est refusé.
Les DDL MySQL ne sont pas transactionnelles : une coupure entre 006 et 007
laisse un état 006 connu, que le prochain appel peut achever sans fabriquer
d'affectation. Après migration vérifiée, l'enrôlement garde sa transaction
SQL séparée et conserve ses règles idempotentes existantes.

L'ancienne option `--apply` reste strictement inchangée : **elle n'exécute
aucune DDL** et exige les migrations déjà appliquées. Cette nouvelle option
réduit les prérequis centraux à une invocation, mais ne dispense jamais de
l'opt-in root central et du bootstrap root local sur caseRouter.

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

## Préflight central en lecture seule (sans modification)

Avant l'enrôlement ou une demande d'intervention sur caseRouter, un
administrateur **root du central TouchDeck** peut consulter l'état réel du
registre avec :

```bash
sudo php bin/devmcp-hosting-lab-inspect case
```

`case` est un **nom exact** de `dm_machine.name` : s'il diffère dans la base,
la commande refuse de deviner l'identité et renvoie `machine_not_found`.
La sortie JSON donne le vrai `machine_id`, l'état de l'identité agent,
l'état SQL `missing` / `006_only` / `ready`, le drapeau opt-in,
les trois identifiants et ports s'ils sont effectivement présents, ainsi
que les éventuelles tâches SQL les plus récentes. Elle retourne le code 2
lorsqu'un prérequis central manque, 1 pour une erreur, 0 seulement si tous
les prérequis **centraux** sont réunis.

L'inspection exige le fichier central root-owned de configuration et des
identifiants de connexion DB existants, mais **n'exige pas d'avoir déjà
activé le drapeau**. Elle ne lit pas les secrets HMAC et ne les expose pas.
Aucune DDL, transaction d'écriture, création d'affectation, mise en file,
élévation de privilège ni connexion à caseRouter n'est déclenchée.

Un résultat `central_queue_prerequisites_met=true` ne certifie **ni**
les droits root locaux, **ni** une écoute HTTP/WS, **ni** le déploiement :
`deployment_complete=false` et `peer_health_verified=false` sont
systématiquement retournés. Utiliser les vrais IDs avant d'appliquer
l'approbation root `--bootstrap` ou `--apply` ; aucun opt-in n'est activé
par cette commande. Le suivi reste dans l'issue #138.
