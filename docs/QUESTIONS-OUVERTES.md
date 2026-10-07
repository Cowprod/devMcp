# Questions ouvertes

Aucune question bloquante pour J01.

## Q-001 — hôte de production
Statut : À TRAITER AVANT DÉPLOIEMENT HTTP
Question : quelle machine hébergera le gateway devMcp exposé à ChatGPT ?
Impact : réseau, service systemd, tunnel/HTTPS et emplacement des runners.

## Q-002 — séparation des runners
Statut : À TRAITER POUR LE JALON RUNNERS
Question : quels projets nécessitent un utilisateur Unix dédié et/ou un runner distant ?
Impact : isolation supplémentaire par projet.

## Q-003 — workspaces locaux
Statut : À TRAITER AVANT ACTIVATION DE CHAQUE PROJET
Question : chemin réel du checkout de chacun des six projets sur chaque machine cible.
Impact : manifests locaux.

## Q-004 — première cible matérielle
Statut : À TRAITER POUR LE JALON HARDWARE
Question : prioriser Android/ADB, ESP32/série ou caméra/gPhoto2.
Impact : ordre des adapters ; aucun impact sur J01.
