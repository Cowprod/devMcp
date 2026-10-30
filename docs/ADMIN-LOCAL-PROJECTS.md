# Administration web : projets locaux et distribués

## Pourquoi deux registres ?

Le runtime MCP conserve les projets et les actions locales dans \`/etc/devmcp/global.php\`.
L'administration distribuée HTTPS consulte \`dm_project\` et \`dm_deployment\` dans MySQL.
Les deux ensembles sont distincts : une absence de MultiCam dans \`dm_project\` ne signifie
pas que le projet ou ses actions MCP ont disparu.

L'interface présente maintenant une **union en lecture seule** : les projets distribués
enregistrés dans MySQL et les projets locaux d'un instantané public minimal. Si un
identifiant appartient aux deux registres, la ligne porte le badge « Local + distribué ».

Les formulaires de création et d'affectation existants continuent de modifier **uniquement**
le registre distribué. Ils ne modifient jamais \`/etc/devmcp/global.php\`.

## Frontière de sécurité

Le processus PHP-FPM \`www-data\` ne lit **jamais** \`/etc/devmcp/global.php\`
(qui peut contenir des secrets). Le processus \`devmcp\` lit le fichier privé,
instancie le même \`ProjectRegistry\` que le serveur MCP, puis publie
uniquement identifiant, description, chemin du workspace, nombre et noms d'actions.
Aucune commande, argument, secret, jeton ou variable d'environnement n'est exporté.

La publication atomique est faite par :

\`\`\`sh
DEVMCP_CONFIG=/etc/devmcp/global.php /usr/bin/php /opt/devmcp/bin/devmcp-admin-projects-export
\`\`\`

Le fichier \`/var/lib/devmcp/admin-share/projects.json\` est écrit avec les droits \`0640\`
dans un répertoire \`devmcp:www-data\` de mode \`2750\`. Sa lecture par PHP-FPM
est possible sans accès aux secrets du runtime. Ne pas mettre cet instantané
dans le document root public d'Nginx.

### Installation initiale (privilège root requis une seule fois)

\`\`\`sh
sudo install -d -o devmcp -g www-data -m 2750 /var/lib/devmcp/admin-share
sudo -u devmcp /usr/bin/php /opt/devmcp/bin/devmcp-admin-projects-export
\`\`\`

L'export doit être réexécuté après toute modification de configuration locale,
et peut être planifié via un timer systemd (voir \`deploy/systemd/devmcp-admin-projects.service\`
et \`devmcp-admin-projects.timer\`).

Le déploiement de \`/opt/devmcp-admin\` est indépendant de celui de \`/opt/devmcp\`.
Les deux copies doivent recevoir les mêmes classes avant d'activer l'affichage local.

En l'absence d'instantané lisible, la page continue d'afficher les projets
distribués et signale explicitement l'indisponibilité des projets locaux.
