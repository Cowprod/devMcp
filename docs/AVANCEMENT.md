# Avancement

| Jalon | Statut | Branche / PR | Objet | Preuves |
| --- | --- | --- | --- | --- |
| J01 | PASS TECHNIQUE | feat/j01-core / PR #2 | cœur MCP sécurisé, registre projets, actions synchrones, audit, CI | GitHub Actions run 37655088534 : SUCCESS ; 5 tests PHPUnit incluant la construction du serveur MCP |
| J02 | À FAIRE | - | jobs asynchrones et artefacts | - |
| J03 | À FAIRE | - | Streamable HTTP sécurisé + authentification | - |
| J04 | À FAIRE | - | runners/agents distants et isolation par projet | - |
| J05 | À FAIRE | - | adapters Android/services/matériel selon priorité | - |

## Gate J01

- objectif V1 : défini ;
- périmètre J01 : défini ;
- architecture générale : définie ;
- shell arbitraire : explicitement interdit ;
- référentiels : identifiés et versionnés ;
- question bloquante J01 : 0.

## Revue technique J01

- Composer validate : PASS ;
- installation mcp/sdk 0.8.1 : PASS ;
- PHPUnit : PASS ;
- construction du serveur via le SDK officiel : PASS ;
- PR : #2, conservée en draft jusqu'à revue/acceptation humaine.
