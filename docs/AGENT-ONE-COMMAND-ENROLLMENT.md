# Installation simplifiée des agents distants (cible)

## Expérience opérateur

Sur une machine Ubuntu/Debian déjà reliée au réseau ZeroTier privé, l'administrateur
ouvre l'interface Web centrale, choisit **Ajouter une machine**, renseigne un nom,
puis obtient une commande d'installation **à usage unique**. Il exécute cette
commande sur la machine distante avec sudo. Le serveur affiche ensuite une demande
d'approbation; aucune action distante n'est autorisée avant validation.

La commande cible est de la forme :

```sh
curl --fail --show-error --silent --proto '=https' --tlsv1.2 \
  https://<IP_ZEROTIER>/bootstrap/agent-install.sh -o /tmp/devmcp-agent-install.sh
# Vérifier l'empreinte SHA-256 affichée dans l'interface avant exécution.
sudo sh /tmp/devmcp-agent-install.sh --enrollment-token '<TOKEN_UNIQUE>'
```

**Ceci décrit l'interface cible, pas une commande opérationnelle aujourd'hui.**
Le script public ne doit jamais contenir de secret embarqué. Ne pas fournir
de variante `curl | sudo bash`.

## Contrat de sécurité à implémenter

1. Le serveur crée un jeton aléatoire fort, lié à une machine, à usage unique,
   à durée de vie courte; stocker uniquement son empreinte et l'auditer.
2. L'installateur refuse l'exécution sans root, sans Ubuntu/Debian compatible,
   sans PHP requis, sans connectivité ZeroTier et sans validation TLS.
   Vérifier l'empreinte du paquet/script signé ou épinglé avant installation.
3. Créer un utilisateur système non privilégié `devmcp-agent`, des répertoires
   privés, un service et un timer systemd. Installation idempotente et reprise
   propre en cas d'échec; ne pas écraser une identité existante.
4. Effectuer l'enrôlement sur HTTPS/ZeroTier, échanger le jeton temporaire
   contre une identité et un secret propres à la machine; protéger les secrets
   avec permissions strictes, ne jamais les afficher ni les journaliser.
5. La machine apparaît `pending_approval` dans l'interface centrale.
   L'approbation humaine active seulement les actions explicitement autorisées.
6. La politique d'actions reste locale, signée/épinglée et non modifiable
   par les ordres du serveur. Pas de shell arbitraire ni d'argv fourni par
   le serveur. Exécuter avec des droits minimaux.
7. Révocation et rotation des secrets, réinstallation contrôlée, mises à jour
   épinglées, journalisation expurgée, diagnostics et désinstallation documentés.
8. Tester la totalité du flux sur deux machines réelles, notamment jeton expiré,
   réutilisé, machine inconnue, TLS invalide, service arrêté et restauration.

## État actuel

Le dépôt contient des composants expérimentaux d'agent, mais le parcours
d'enrôlement en une commande n'est **pas implémenté ni validé**. Les blocages
décrits dans `docs/DISTRIBUTED-DEPLOYMENT.md` et les security gates doivent
être levés avant de présenter la commande comme installable.
