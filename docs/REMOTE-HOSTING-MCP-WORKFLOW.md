# Déploiement des trois démos depuis le MCP

Sur le projet local `devmcp-admin`, les actions installées sont :

1. `hosting.lab.inspect` : vérifier les trois affectations et les jobs.
2. `hosting.lab.prepare` avec `revision` : préparer les trois releases.
3. Après les trois préparations réussies, `hosting.lab.activate` avec le même SHA.
4. Après les trois activations réussies, `hosting.lab.verify` sans argument.
5. Attendre les jobs de commit, puis répéter `hosting.lab.verify` : le résultat
   doit contenir `deployment_complete=true`.

Utiliser `action_start`, puis `job_status` et `job_output` pour chaque action.
Une action d'enfilage terminée ne signifie pas que les jobs distants le sont.

La vérification s'exécute sur touchDeck, pair Linux ZeroTier indépendant de
caseRouteur. Elle teste les pages HTTP, les routes de santé PHP/Node et l'écho
WebSocket avant de transmettre les trois observations signées au gateway.
Les identifiants d'activation proviennent de SQL, jamais d'une valeur inventée
par ChatGPT. Une répétition ne duplique pas les commits en cours ou réussis.

Configuration opérateur dans `/etc/devmcp-admin/distributed.php` : les deux
adresses réelles `hosting_lab_target_ip` et `hosting_lab_peer_ip`. La clé binaire
de 32 octets, propriétaire root, mode 0600, est conservée dans
`/etc/devmcp/hosting-peer-key.bin` ; elle correspond au secret local du gateway.
Elle n'est jamais retournée par l'action ni écrite dans les logs.

L'agent consomme jusqu'à trois jobs hosting par passage, puis s'arrête si la file
est vide. Cela permet au lot de trois commits d'être traité avant son échéance
de deux minutes avec le timer d'une minute.

Ce circuit concerne `lab-static`, `lab-php`, `lab-node` et leurs ports fixes.
Il ne fournit pas une création ou un déploiement distant de projet arbitraire.
