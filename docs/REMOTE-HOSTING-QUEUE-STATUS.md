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
  verbes `prepare-all`, `activate-initial` et `abort-initial`.

## Conditions d'activation — NON satisfaites

1. La migration SQL 006 doit être appliquée par une procédure centrale revue ;
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
6. Un mécanisme **authentifié d'enqueue** et une confirmation finale par
   contrôle de santé depuis un second pair ZeroTier restent à développer.
   Ne pas ajouter d'entrée à la table par API publique ou par mécanisme
   read-only existant.

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
