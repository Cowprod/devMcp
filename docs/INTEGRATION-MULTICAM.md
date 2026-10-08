# Intégration MultiCam

## Objectif

MultiCam est le premier projet réel utilisé pour qualifier la boucle complète :

GitHub
→ commit exact
→ workspace VM
→ build Android
→ artefact APK
→ inventaire ADB
→ installation
→ logs
→ correction GitHub

Le manifest de référence est :

deploy/config/multicam.vm.example.php

## Sources MultiCam qualifiées

Dépôt : Cowprod/multicam

Le dépôt fournit déjà :

- app/setup-android.sh
- tests/e2e/devices.sh
- tests/e2e/install-all.sh
- tests/e2e/screenshot-all.sh
- configuration Cordova Android dans app/package.json

setup-android.sh installe les dépendances npm, prépare Cordova Android, applique les patches Android qualifiés puis exécute cordova build android.

L'APK attendu est :

app/platforms/android/app/build/outputs/apk/debug/app-debug.apk

Package Android :

fr.emmanuel.multicam

## Actions devMcp préparées

### workspace.sync

Entrée :
- commit : SHA Git complet de 40 caractères.

Comportement :
- refuse un workspace avec fichiers suivis modifiés ;
- vérifie que origin correspond à Cowprod/multicam ;
- fetch origin ;
- vérifie que le commit appartient à une ref distante origin ;
- checkout detached exactement sur ce SHA.

Cette action rend reproductible la relation entre le commit inspecté sur GitHub et le code réellement testé sur la VM.

### android.build_debug

Exécute le setup Android déjà versionné dans MultiCam.

Artefact déclaré :
- app-debug.apk
- type application/vnd.android.package-archive
- taille et SHA-256 accessibles par artifact_list.

### android.devices

Réutilise le harness MultiCam existant et sa politique d'appareils autorisés.

### android.install_all

Installe l'APK produit sur tous les appareils autorisés par le harness.

### android.install_one

Paramètre :
- serial de type android_serial.

Le serial devient un élément argv distinct. Il n'est jamais concaténé dans une ligne de shell.

### android.force_stop

Arrêt du package fr.emmanuel.multicam sur un appareil précis.

### android.logcat_dump

Lecture logcat d'un appareil précis.

## Séquence autonome cible

1. ChatGPT termine une modification GitHub et récupère le SHA du commit à qualifier.
2. action_start(multicam, workspace.sync, commit=SHA).
3. attendre succeeded.
4. action_start(multicam, android.build_debug).
5. attendre succeeded.
6. artifact_list → vérifier présence, taille, SHA-256 APK.
7. action_start(multicam, android.devices).
8. lire la liste des appareils autorisés.
9. action_start(multicam, android.install_all) ou android.install_one.
10. exécuter la campagne ciblée appropriée.
11. récupérer job_output/logcat/preuves.
12. corriger le dépôt via GitHub si nécessaire puis recommencer sur le nouveau SHA.

## Frontière de confiance

Le modèle peut fournir uniquement les paramètres déclarés par l'action.

Types J05 :
- git_sha ;
- android_serial ;
- enum ;
- integer borné.

Le modèle ne fournit jamais :
- executable ;
- cwd ;
- target ;
- chemin d'artefact ;
- argument non déclaré ;
- fragment de shell.

## Qualification physique restante

Le code générique et le manifest peuvent être validés en CI, mais les points suivants requièrent la VM et le matériel :

- versions Java/Android SDK réellement installées ;
- chemin adb réel ;
- droits USB/udev si appareils branchés en USB ;
- visibilité ADB des tablettes ;
- durée réelle de setup-android.sh ;
- génération effective de l'APK ;
- première installation ;
- premier logcat ;
- connexion ChatGPT → Secure MCP Tunnel → VM.

Aucun de ces points ne doit être déclaré PASS sur simulation CI.
