# Qualification technique

## MCP PHP

Question : existe-t-il un SDK PHP officiel suffisamment adapté au serveur générique ?

Résultat : oui.

Source officielle : https://github.com/modelcontextprotocol/php-sdk
Documentation : https://php.sdk.modelcontextprotocol.io/
Package Composer : mcp/sdk

Au 7 octobre 2026, la version stable retenue est 0.8.1. Le SDK requiert PHP 8.1+ et fournit STDIO et Streamable HTTP.

Conclusion : utiliser le SDK officiel et pinner 0.8.1 afin d'éviter qu'une évolution pré-1.0 casse implicitement le projet.

## Streamable HTTP PHP

La documentation officielle du SDK impose un stockage de session persistant pour le mode HTTP stateful, car des requêtes successives peuvent être traitées par des processus différents.

Qualification retenue :

- StreamableHttpTransport du SDK ;
- PSR-7 ;
- FileSessionStore pour le premier déploiement mono-VM ;
- middleware de sécurité par défaut du SDK conservé ;
- endpoint local 127.0.0.1 dans le template systemd.

Conclusion : HTTP local est qualifié, mais n'est pas nécessaire pour le chemin ChatGPT privilégié.

## Secure MCP Tunnel OpenAI

Sources officielles :

- https://developers.openai.com/api/docs/guides/secure-mcp-tunnels
- https://github.com/openai/tunnel-client

Le client OpenAI Secure MCP Tunnel est explicitement destiné aux MCP privés ou localhost et permet de les connecter à ChatGPT sans endpoint MCP public.

Le tunnel-client prend en charge :

- un MCP local Streamable HTTP ;
- un MCP lancé en STDIO via --mcp-command ;
- un contrôle de santé/doctor ;
- une Runtime API key distincte des Admin API keys.

La documentation officielle indique que :

- CONTROL_PLANE_API_KEY est la clé runtime utilisée par doctor/run ;
- CONTROL_PLANE_TUNNEL_ID identifie le tunnel ;
- OPENAI_ADMIN_KEY ne sert qu'aux opérations administratives de création/gestion du tunnel ;
- la clé runtime doit disposer de Tunnels Read + Use ;
- le daemon long-lived ne doit pas utiliser la clé admin ;
- un tunnel STDIO ne doit avoir qu'une seule instance tunnel-client active par tunnel ID.

Conclusion : le binding STDIO est le meilleur choix pour le premier déploiement devMcp. Il conserve le serveur hors Internet et évite une couche d'authentification HTTP propriétaire.

## Authentification

Pour un MCP public authentifié, l'intégration ChatGPT attend le modèle OAuth compatible MCP plutôt qu'une API key arbitraire fournie par l'utilisateur.

Conclusion : ne pas créer de Bearer token propriétaire pour le chemin ChatGPT. Secure MCP Tunnel constitue la frontière d'accès du premier déploiement.

## Jobs persistants

Le transport distant implique que deux appels MCP successifs peuvent provenir de processus distincts. Les jobs J02 uniquement détenus en mémoire sont donc insuffisants.

Qualification retenue :

- métadonnées JSON atomiques par job ;
- logs stdout/stderr sur disque et bornés ;
- verrou par job ;
- verrou global uniquement pendant le claim ;
- worker séparé ;
- job persistant ne contient que project + action, jamais argv.

Conclusion : même si un fichier job est manipulé, le worker recharge la commande depuis la configuration de confiance et n'exécute pas un argv fourni par la file.

## Exécution sans shell

Symfony Process accepte une commande sous forme de tableau argv.

Le worker utilise également l'itérateur non bloquant de Symfony Process pour drainer régulièrement stdout/stderr et éviter que les sorties longues restent accumulées dans le processus.

Conclusion : cette primitive convient au runner local sous réserve de conserver l'argv statique et l'allowlist de manifest.

## Risque SDK pré-1.0

Le SDK MCP PHP officiel reste pré-1.0.

Conclusion : l'intégration SDK est encapsulée dans ServerFactory et les entrypoints ; registre, politique de sécurité, jobs et artefacts restent indépendants des classes internes du SDK.
