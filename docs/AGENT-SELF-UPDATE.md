# Agent distant — auto-update à installation unique

## Statut

Capacité opt-in : les installations existantes ne sont **pas** modifiées
automatiquement par une mise à jour de devMcp central.

La première installation du mécanisme nécessite une seule intervention
administrateur sur la machine distante déjà enrôlée. Les contrôles ne
s'appliquent pas aux fonctions de routage, au pare-feu ni aux services de
caseRouter.

## Fonctionnement après installation

- `devmcp-agent-update.timer` vérifie le dépôt `Cowprod/devMcp`, branche
  `main`, au démarrage puis toutes les 6 heures, avec délai aléatoire.
- Une seule mise à jour à la fois est autorisée (verrou `flock`).
- Le dépôt Git de mise à jour, les sauvegardes temporaires et le SHA de
  version sont dans `/var/lib/devmcp-agent-update`, **root-only**.
  Ne jamais stocker ces données dans `/var/lib/devmcp-agent`, modifiable
  par l'utilisateur non privilégié `devmcp-agent`.
- Le script refuse un dépôt non conforme, les modifications locales,
  les liens symboliques et les révisions Git non fast-forward.
- L'updater se **met également à jour lui-même** : sa nouvelle version est
  vérifiée avec `bash -n` puis remplacée par renommage atomique, après
  redémarrage réussi du service et du timer de l'agent. Il conserve une
  sauvegarde de l'ancien script jusqu'à la validation de la révision.
  Une future mise à jour pourra étendre la liste fixe des fichiers PHP
  distribués sans exiger un nouveau bootstrap sur chaque machine.
  Les unités systemd restent épinglées à l'installation initiale :
  l'auto-update ne leur confère pas de nouveaux privilèges.
- Les six fichiers de code fixés dans `self-update-agent.sh` sont préparés
  dans une zone temporaire et chacun passe `php -l` avant remplacement.
- Si une nouvelle version de l'updater étend la liste fixe des fichiers
  distribués, le contrôle suivant vérifie **les fichiers effectivement présents**,
  même lorsque le SHA mémorisé correspond déjà à `origin/main`. Les helpers
  manquants sont installés lors de cette passe ; un binaire déjà présent mais
  différent du SHA attendu provoque un refus explicite (pas d'écrasement silencieux).
- Le marqueur `installed-revision` est préparé dans un fichier temporaire du
  même système de fichiers puis remplacé par renommage atomique. Une panne
  au milieu de cette écriture ne doit pas tronquer le marqueur précédent.
- Le timer principal de l'agent est arrêté pendant la copie, le service
  est redémarré, puis le timer est rétabli. En cas d'échec de l'activation,
  le script restaure les anciens binaires.
- Aucun fichier sous `/etc/devmcp` n'est modifié. Le script n'installe
  aucune dépendance, ne lance pas de migration et ne modifie pas les droits
  d'actions. La politique locale continue de refuser toute nouvelle action
  sans autorisation explicite.

## Bootstrap en une seule intervention

Une fois la PR fusionnée et le commit exact vérifié dans GitHub, sur `case` :

1. Télécharger `deploy/distributed/install-agent-self-update.sh` depuis
   `https://raw.githubusercontent.com/Cowprod/devMcp/<SHA_FUSION>/...`,
   sur disque local. Vérifier que la provenance correspond au SHA immutable
   annoncé. Ne pas utiliser `curl | sudo bash`.
2. L'exécuter en root avec ce **même** SHA en argument.
   Il récupère uniquement l'updater et ses deux unités systemd depuis
   ce commit, vérifie la syntaxe Bash et les unités, crée l'état root-only,
   active le timer puis effectue le premier contrôle de mise à jour.

L'identité d'enrôlement doit déjà exister. Une nouvelle machine nécessite
toujours son enrôlement initial avant ce bootstrap.

La procédure vérifie `systemctl is-enabled devmcp-agent-update.timer` et
la réussite de la première exécution. Après cette intervention, le timer
assure les mises à jour du code de l'agent.

## Frontières d'autorisation

**Auto-update du code ≠ approbation de nouvelles actions.**

L'auto-update permet de distribuer des correctifs et de nouveaux types de
diagnostic. Il ne modifie jamais
`/etc/devmcp/remote-readonly-policy.php`, la configuration d'enrôlement
ni les droits système. Par exemple, `host.runtime` restera refusé avec
`local_policy_denied` jusqu'à l'autorisation locale correspondante.

Pour permettre ultérieurement le déploiement des applications statique,
PHP et Node, il faudra concevoir et approuver une politique de déploiement
bornée par projet, répertoire, port et runtime. Cette décision est distincte
de l'auto-update et n'est pas implicitement accordée par ce mécanisme.

## Diagnostic d'accessibilité après bootstrap

Le succès du service systemd `devmcp-agent-update.service` confirme la
synchronisation du code, **pas** l'acceptation des pings par le serveur.
Un `devmcp-agent.service` `inactive (dead)` est normal pour cette unité
`oneshot` déclenchée par un timer.

Lorsqu'un ping `/v1/agent/ping` n'obtient pas HTTP 200, l'agent journalise
désormais une catégorie stable, le statut HTTP et le numéro d'erreur cURL
(mais jamais le jeton, la requête, l'URL complète, le corps de réponse ni
le texte d'erreur TLS). Le statut HTTP 400 est ambigu : le serveur central
l'utilise notamment pour plusieurs rejets internes, **sans prouver** que
l'enrôlement est en cause. HTTP 404 suggère une route absente ; HTTP 0
avec un numéro d'erreur cURL signale une panne de transport ou TLS.
Les paramètres d'authentification et la politique restent inchangés.

L'amélioration du journal est distribuée par l'auto-update déjà installé,
au prochain cycle (environ six heures, plus un délai aléatoire). Aucune
relance manuelle ni nouvelle autorisation locale n'est requise.

## Limites

- L'updater suit `main` en mode fast-forward ; le contrôle principal
  d'intégrité et de provenance repose sur Git HTTPS et sur les droits
  de publication du dépôt GitHub. Il ne valide pas de signatures GPG.
- Il vérifie la syntaxe des binaires PHP, mais pas automatiquement une
  campagne de tests fonctionnels sur la machine cible. Le démarrage
  réussi du service ne prouve pas que toutes ses fonctions sont correctes.
- Le rollback couvre une erreur d'activation détectée lors de la mise à jour ;
  il ne détecte pas un dysfonctionnement applicatif apparaissant plus tard.
- L'écriture atomique du **marqueur** ne rend pas les remplacements des six
  binaires atomiques comme un groupe. Une coupure d'alimentation pendant la
  copie peut nécessiter une reprise locale. Cette limitation reste à lever
  avant un mécanisme de mise à jour transactionnelle complet.
- La connectivité sortante HTTPS vers GitHub doit être autorisée sur la
  machine cible.
