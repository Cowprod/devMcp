# devMcp

MCP PHP générique servant de plan d’exécution contrôlé pour les projets Cowprod.

Objectif : permettre à ChatGPT de piloter builds, tests, services et matériel sans exposer de shell arbitraire. Les capacités sont déclarées projet par projet, validées côté serveur et auditées.

## Principes

- aucune commande shell libre ;
- actions nommées et bornées par projet ;
- exécution par argv structurés, jamais par concaténation de shell ;
- chemins confinés au workspace déclaré ;
- secrets conservés côté serveur ;
- audit append-only ;
- opérations longues destinées à devenir des jobs asynchrones ;
- GitHub natif utilisé pour le plan de contrôle du code ; devMcp pour l’exécution locale.

La conception et les jalons sont documentés dans `docs/`.
