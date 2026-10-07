# Mandat J03 — transport distant et persistance inter-requêtes

Tu arrives sur ce projet sans contexte antérieur. Git est la source de vérité.

Branche : feat/j03-http-tunnel

## Objectif

Rendre devMcp utilisable depuis ChatGPT à distance sans exposer une surface publique inutile, tout en conservant les jobs entre requêtes/processus PHP.

## Décision de transport

Chemin recommandé :
Secure MCP Tunnel → binding STDIO → bin/devmcp.

Chemin secondaire :
Secure MCP Tunnel ou test local → Streamable HTTP → public/index.php.

## Obligatoire

- file de jobs persistante ;
- worker séparé ;
- logs de jobs persistants et bornés ;
- annulation par drapeau persistant ;
- timeout appliqué par le worker ;
- Streamable HTTP du SDK officiel ;
- FileSessionStore pour HTTP ;
- endpoint HTTP local uniquement dans le template systemd ;
- conservation des protections DNS rebinding/CORS par défaut du SDK ;
- aucune authentification maison exposée publiquement.

## Tests

- un manager crée un job, un worker séparé l'exécute, un nouveau manager retrouve le résultat ;
- timeout worker ;
- annulation avant claim ;
- artefacts après job persistant ;
- initialisation MCP via Streamable HTTP ;
- régression STDIO / serveur factory.

## STOP

STOP si la solution impose :
- un serveur MCP public sans besoin ;
- une API key statique inventée que ChatGPT ne sait pas présenter ;
- un shell arbitraire ;
- une Admin API key OpenAI dans un daemon runtime.
