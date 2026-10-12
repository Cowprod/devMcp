# Node.js / PM2 : actions bornées

Cette extension conserve la séparation GitHub (code) / devMcp (exécution). Aucun shell arbitraire n'est ajouté.

## Actions

- `workspace.list`, `workspace.stat`, `workspace.read` : déjà présentes ; lecture bornée à 64 KiB et 500 lignes pour read.
- `workspace.find` : profondeur maximale 5, 200 résultats, 2000 entrées examinées, filtre énuméré `all|php|js|json|md`. Les liens symboliques ne sont jamais parcourus.
- `node.version` : Node installé, sans dépendance à npm.
- `node.dependencies` : `npm ci --no-audit --no-fund` si lockfile présent, sinon `npm install` pour amorcer un lockfile (à versionner ensuite).
- `node.test`, `node.build` : uniquement les scripts présents dans `package.json`, avec actions explicitement activées par projet.
- `service.start|stop|restart|reload|status|logs|health` : service PM2 à nom, entrée, cwd et port statiques, dans un PM2_HOME dédié.

Les définitions `node.*` et `service.*` sont désormais dans `config/shared-actions.php`. Chaque projet n'en active que les identifiants nécessaires dans `shared_actions` ; les valeurs propres au projet sont dans `node.cwd` et `service.{name,entry,port,health_path}`. Aucun `argv` spécifique au projet n'est nécessaire. Le moteur substitue ces champs via une liste blanche et valide leurs formats. `node.build` reste disponible globalement mais n'est pas activé pour TouchDeck.\n\nLes actions npm exécutent du code du dépôt, y compris les hooks d'installation. L'administrateur doit n'activer ces actions que sur un dépôt et une révision de confiance. Les chemins d'exécutables Node, npm et PM2 doivent être vérifiés sur chaque hôte. Le PM2 helper refuse un nom déjà occupé par un service dont le cwd et l'entrée ne correspondent pas.

## Préparation VM (administrateur)

1. Installer Node.js >= 22 et npm ; vérifier `command -v node npm pm2`. Réutiliser un PM2 existant lorsque compatible ; éviter les installations concurrentes.
2. Créer `/var/lib/devmcp/pm2`, propriétaire `devmcp`, permissions 0700. Cette instance PM2 dédiée évite d'agir sur les processus d'autres utilisateurs.
3. Fusionner les définitions de `deploy/config/touchdeck.vm.example.php` dans `/etc/devmcp/global.php` sans remplacer la configuration ni les secrets existants.
4. Déployer `/opt/devmcp` depuis un commit validé et redémarrer `devmcp-tunnel` et `devmcp-worker`.
5. Pour la persistance après reboot, configurer un service systemd PM2 spécifique à l'utilisateur `devmcp` avec `PM2_HOME=/var/lib/devmcp/pm2`, puis activer le service. Ne pas exécuter `pm2 startup` à l'aveugle : il peut cibler une autre instance.
6. Vérifier le binding loopback : `127.0.0.1:3000` et le healthcheck `/api/health` avant toute exposition réseau.

L'exemple TouchDeck n'active pas `node.build` car le projet ne déclare aucun script build. Le dépôt TouchDeck ne contient pas encore de `server/package-lock.json` sur main au moment de la rédaction : la première installation utilisera `npm install` et produira un lockfile local non versionné ; le faire valider et versionner dans TouchDeck avant de considérer l'installation reproductible.

## Limites de sécurité

Les exclusions de noms sensibles sont une défense en profondeur, **pas** une garantie universelle de non-divulgation : un fichier mal nommé peut contenir des secrets. Les logs PM2 ne sont expurgés que pour quelques motifs de credentials connus ; ne pas activer leur consultation pour un service qui imprime des secrets. Les commandes npm et PM2 nécessitent des droits d'exécution locaux et ne doivent pas être autorisées pour des dépôts non fiables.

## Qualification attendue

CI : lint PHP, tests Composer, smoke tests workspace. Sur VM : `action_list`, chemins refusés, `node.version`, installation `npm ci`, `node.test`, start/status/health/logs/stop/restart et persistance reboot. Tant que la VM n'est pas reconfigurée, ces contrôles réels restent **non validés**.
