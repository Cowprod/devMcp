# Hébergement distant caseRouter — état de la file dédiée (9 novembre 2026)

## Chaîne ajoutée dans la PR #146

- Table `dm_remote_hosting_job` (migration 006), **indépendante** de
  `dm_remote_readonly_job`.
- `agent-hosting-poll.php` : authentification machine TLS + bearer existante,
  état `active`, jointure déploiement activé, claim SQL transactionnel.
  Une demande non conforme est échouée, jamais transmise à l'agent.
- `agent-hosting-result.php` : acquittement lié au couple job/machine,
  refus d'une prétendue finalisation ou santé externe.
- `remote-hosting-queue.php` : consommateur séparé, opt-in racine,
  IPC Unix contrôlé, un job maximum par interrogation. **Aucun shell
  arbitraire** et aucune réutilisation des actions `workspace.*`.
- `devmcp-hosting-gateway` : exécution locale root avec uniquement les
  verbes `prepare-all`, `activate-initial`, `abort-initial` et `commit-initial`
  (ce dernier exige une preuve HMAC vérifiée localement par root).

## Conditions d'activation — NON satisfaites

1. Les migrations SQL 006 **puis 007** doivent être appliquées par une procédure centrale revue ;
   l'existence du fichier SQL ne modifie pas la base distante.
2. La configuration serveur `remote_hosting_queue_enabled=true` doit
   être approuvée et installée hors dépôt. Valeur absente = refus.
3. La politique locale agent `/etc/devmcp/hosting-agent-policy.php`
   doit être créée explicitement par root (0644, propriétaire root, champs
   exactement `enabled` et `machine_id`). Elle ne doit pas être fournie
   par un job de la file.
4. La politique locale privilégiée `/etc/devmcp/hosting-policy.json`
   doit être créée par root (0600) avec trois déploiements préapprouvés.
5. Installer séparément et valider le socket systemd root-only et ses
   ressources root-owned. L'auto-update installe seulement du code inerte.
6. L'enqueue root-only existe désormais, mais n'est pas activé sur la machine
   centrale. La sonde signée externe et le nouveau transport `commit-initial`
   restent à valider dans des conditions réseau réelles. Ne jamais ajouter
   d'entrée via une API publique ou une action read-only.

## Protocole et limites

Les seuls messages autorisés utilisent `version=1`, une identité machine,
un déploiement, un projet et un SHA Git complets. Le nonce 32 hex est
obligatoire pour `activate-initial` et `abort-initial`.
Les opérations sont fail-closed et n'affirment jamais la validation terrain.

L'agent ne doit jamais considérer `result.ok=true` ou le statut SQL
`succeeded` comme une preuve de succès du déploiement. Cela signifie
seulement que l'exécuteur a terminé le verbe demandé. Un service en
`service_running_pending_peer_health` reste **non validé**.

Pas de modification WAN/LAN, routage, NAT, pare-feu, Nginx du routeur,
`caseRouter` ou services critiques. Aucun bootstrap de production n'a
été exécuté dans cette PR.

**État physique attesté : 0/3 applications.**

## Contrôle de santé signé et commit initial (PR #146)

La sonde `deploy/distributed/hosting-peer-probe.py` doit tourner sur
**un autre pair ZeroTier**, avec une clé HMAC de 32 octets conservée dans
un fichier local 0600 appartenant à son utilisateur. Elle exige les
identifiants exacts du déploiement, un nonce d'activation, le SHA Git,
le port et les IPv4 source/destination, puis vérifie HTTP et, pour Node,
la réponse WebSocket. Elle signe une observation canonique JSON.

Le routeur ne reçoit que l'observation signée. Son administrateur
prépare explicitement `/etc/devmcp/hosting-peer-proof.json` root:root
0600 avec exactement deux clés, dans cet ordre :
`{"source_peer_ip":"<IP_ZEROTIER_PAIR>","hmac_key_hex":"<64_HEX>"}`.
La clé hex est la représentation des mêmes 32 octets secrets que le
fichier de la sonde. **Ne jamais transmettre la clé à l'agent, au central,
à la file de jobs ou à un dépôt Git.**

Une fois l'activation dans l'état pending, l'administrateur root peut
utiliser `php /opt/devmcp-agent/bin/hosting-active/devmcp-hosting-root
commit-initial` avec un unique JSON sur stdin regroupant les
identifiants, `operation_id` et `proof`. La commande peut désormais être
transportée via la file hosting **distincte** après opt-in explicite et
présentation de la preuve HMAC signée. La file, l'agent et le central ne
possèdent pas la clé : seul le processus root sur caseRouter vérifie la
signature, l'identité, le SHA et la fraîcheur. Une tâche centrale ne peut
pas fabriquer une preuve cryptographique de succès.

Le commit root vérifie à nouveau la politique locale, le journal
d'activation, le pointeur `current`, la provenance de la release, la
signature HMAC, sa fraîcheur (120 secondes, avance maximale 15 secondes)
et le service applicatif actif. Il archive ensuite l'intention sous
son nonce, empêchant la réutilisation. Une signature invalide laisse
le journal non résolu, jamais « déployé ». Le mécanisme ne certifie
**que la première activation** : les upgrades restent bloqués.

Reste à développer et vérifier : acheminement sûr de la preuve depuis
le pair (hors agent), ordonnanceur de délai/rollback des activations
pending et exécution réelle du bootstrap, de la migration SQL et des
trois prises de service. Pas d'intervention distante demandée avant
validation de ce chemin complet.

## Itération suivante — transport distant de `commit-initial`

Le schéma strict `hostingQueueRequest()` et `hostingGatewayDecode()`
autorisent `commit-initial` avec une preuve de pair complète et liée au
même nonce, projet, machine, déploiement et SHA. Le processus root
`RemoteHostingPeerEvidence::verifyLocal()` reste l'unique point
d'authentification HMAC et doit refuser tout reçu non valide ou expiré.
L'agent ne connaît jamais la clé. `agent-hosting-result.php` accepte
désormais un reçu d'exécution positif uniquement pour `commit-initial`
et vérifie sa liaison au job d'origine avant archivage SQL, sans
le considérer comme une observation réseau indépendante : un ACK d'agent
peut être mensonger. La **preuve de déploiement terrain** doit toujours
être obtenue depuis le pair ZeroTier indépendant.

La migration 007 étend l'ENUM SQL `action_name` : sans elle, le central
refuserait l'insertion du job `commit-initial` même avec le code correct.
Elle est ré-exécutable, mais n'est jamais appliquée automatiquement sur
la base réelle. Le workflow MySQL la teste après 006 et une seconde fois.

Ce changement ne crée **aucune autorisation**, ne modifie pas les
actions `caserouter` en lecture seule, et n'installe ni socket, ni
service, ni clé sur caseRouter. Conditions opérationnelles toujours non
satisfaites : politique locale root, installation opt-in du socket,
configuration serveur + migration SQL, pair indépendant et signatures,
et rollback automatique d'une activation en attente.
