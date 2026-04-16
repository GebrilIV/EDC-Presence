#!/usr/bin/env sh
# Démarre un serveur PHP local sur le port 5173 depuis la racine du projet.
cd "$(dirname "$0")" || exit 1
php -S 0.0.0.0:5173 -t .
