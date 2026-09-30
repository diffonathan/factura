#!/bin/sh
# Point d'entrée du conteneur PHP.
#
# `vendor/` vit dans un volume Docker et non dans le dépôt : au tout premier
# démarrage, ce volume est vide. Sans ce script, `artisan serve` échoue sur
# « Failed opening required /app/vendor/autoload.php » et le conteneur s'arrête
# aussitôt — un message qui n'aide personne à comprendre qu'il manque
# simplement une installation.
#
# On la fait donc ici, une seule fois, et le message dit ce qui se passe.
set -e

if [ ! -f /app/vendor/autoload.php ]; then
    echo "→ vendor/ est vide (premier démarrage) : installation des dépendances…"
    composer install --no-interaction --no-progress
    echo "→ dépendances installées."
fi

# `.env` absent : on part de l'exemple et on génère une clé. Un dépôt fraîchement
# cloné démarre ainsi sans préparation manuelle.
if [ ! -f /app/.env ] && [ -f /app/.env.example ]; then
    echo "→ .env absent : création depuis .env.example"
    cp /app/.env.example /app/.env
    php artisan key:generate --no-interaction
fi

exec "$@"
