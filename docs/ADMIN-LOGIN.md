# Connexion à l’admin devMcp

L’admin HTTPS utilise un formulaire HTML et un cookie de session `DEVMCPADMIN`,
Secure, HttpOnly et SameSite Strict. Les en-têtes Basic/REMOTE_USER ne donnent
aucun accès. Le bouton Déconnexion invalide la session. Les routes des agents
conservent leur authentification par jeton propre à chaque machine.

Les identifiants sont dans `/etc/devmcp-admin/web-auth.php`, fichier root:www-data
0640 avec hash bcrypt et version de session. Une modification de cette version
invalide les sessions existantes. Les sessions sont stockées dans
`/var/lib/devmcp-admin-auth`, appartenant à www-data en 0700.

La migration `bin/migrate-admin-form-auth.php --execute MIGRATE_ADMIN_FORM_AUTH`
est exécutée par le déploiement root après synchronisation des deux checkouts.
Elle sauvegarde le vhost et les anciens identifiants dans un dossier root privé,
prépare les identifiants puis désactive HTTP Basic dans le vhost, teste nginx
et recharge le service. En cas d’échec de validation, elle restaure le vhost.
Elle ne change ni l’écoute ZeroTier, ni TLS, ni les routes des agents.

La première migration génère un nouveau mot de passe `admin`. Le secret de
bootstrap est conservé temporairement dans `/run/devmcp-admin-bootstrap-password`
(root:devmcp 0640) et peut être lu par l’action privée `admin.password.bootstrap`
du projet `devmcp-admin`. Il disparaît au redémarrage de la machine ; le hash
reste persistant. Les déploiements suivants ne réinitialisent pas le mot de passe.
Une installation neuve conserve le mot de passe fourni à `DEVMCP_ADMIN_PASSWORD`.
