# Qualification technique

## MCP PHP

Question : existe-t-il un SDK PHP officiel suffisamment adapté au serveur générique ?

Résultat : oui.

Source officielle : https://github.com/modelcontextprotocol/php-sdk
Documentation : https://php.sdk.modelcontextprotocol.io/
Package Composer : mcp/sdk

Au 7 octobre 2026, la version stable Packagist retenue pour J01 est 0.8.1. Le SDK requiert PHP 8.1+ et fournit les transports STDIO et Streamable HTTP.

Conclusion : utiliser le SDK officiel et pinner 0.8.1 pour J01 afin d'éviter qu'une évolution pré-1.0 casse implicitement le projet.

## Exécution sans shell

Symfony Process accepte une commande sous forme de tableau argv. Le cœur J01 n'utilise ni fromShellCommandline ni un interpréteur de commandes.

Conclusion : cette primitive convient au runner synchrone de fondation, sous réserve de valider strictement le manifest et de ne pas accepter d'argv libre depuis MCP.

## Risque SDK pré-1.0

Le SDK officiel est encore annoncé expérimental avant sa version 1.0.

Conclusion : encapsuler le SDK dans bin/devmcp et ProjectTools ; le registre, la sécurité et le runner ne dépendent pas des classes internes du SDK.
