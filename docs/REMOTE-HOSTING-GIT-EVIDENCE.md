# Hébergement distant — preuve Git locale en lecture seule

\`RemoteHostingGitEvidence::inspect($request)\` ne peut être appelé qu'après
validation de la politique locale root-only et de l'identité enrôlée par
\`RemoteHostingLocalPolicy\`. Les quatre champs du sélecteur de release ne
permettent de choisir ni commande Git, ni chemin, ni dépôt arbitraire.

Pour le déploiement approuvé \`<deployment_id>\`, le composant attend un
**miroir Git bare** déjà provisionné par l'administrateur sous
\`/var/lib/devmcp-hosting/mirrors/<deployment_id>.git\`. Il vérifie en lecture
seule la propriété root et les permissions des parents, du miroir et des
fichiers essentiels, sans suivre les liens symboliques. Aucun miroir n'est
créé ni mis à jour.

Les appels Git passent par \`proc_open\` **sans shell**, avec un tableau
d'arguments fixe, un environnement contrôlé et \`/usr/bin/timeout 3s\` :
aucune commande distante, \`git fetch\`, \`clone\`, \`checkout\`, exécution de
hook ou modification de la configuration. La sortie de chaque commande est
bornée à 8 KiB. Les vérifications portent sur :

- le caractère bare du miroir et son URL de provenance exacte
  \`https://github.com/Cowprod/<repo>.git\` ;
- la présence d'un commit local \`refs/heads/main\` ;
- la nature \`commit\` du SHA minimal localement épinglé et du SHA demandé ;
- \`minimum_revision\` ancêtre du SHA demandé ;
- SHA demandé ancêtre du \`main\` **local** du miroir.

La méthode \`check()\` sert exclusivement à des tests déterministes :
des booléens ou SHA envoyés depuis un agent distant ne sont **jamais une
preuve**. Seule \`inspect()\` peut produire l'observation locale ; le
résultat reste toujours \`ready_to_execute=false\`.

**Limites explicites :** une URL d'origine dans la configuration Git n'est
pas une preuve d'authenticité du contenu ni de fraîcheur de \`main\`. Un
miroir local peut être obsolète ou contenir des objets altérés ; le futur
provisionneur devra authentifier et rafraîchir les références, vérifier
l'intégrité des objets, la chaîne de confiance et les protections des
répertoires lors de l'activation. Le contrôle n'assure pas non plus
l'immuabilité du release tree, l'absence de liens/montages dans le contenu,
la réservation exclusive du port, l'isolation des processus, le peer-health
ou le rollback. Les vérifications instantanées restent sujettes au TOCTOU.
Le futur exécuteur devra maintenir un verrou local et réexaminer toutes les
preuves avant une activation.

Aucun système de fichiers de \`case\` n'est modifié, aucune permission
d'exécution distante n'est ajoutée, et aucune fonction réseau de
\`caseRouter\` n'est touchée. Tests :
\`tests/Distributed/RemoteHostingGitEvidenceTest.php\`.
