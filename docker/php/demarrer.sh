#!/bin/sh
# Démarrage en production.
#
# L'ordre compte : on migre AVANT de servir. Un conteneur qui répond avant que
# le schéma soit à jour rend des erreurs pendant quelques secondes, et le
# contrôle de santé peut le déclarer bon alors qu'il ne l'est pas.
set -e

echo "→ migrations"
php artisan migrate --force --no-interaction

# Le jeu de démonstration, une seule fois. `--seed` n'est PAS idempotent :
# le rejouer à chaque déploiement créerait une seconde entreprise Atlas
# Numérique, puis une troisième. On regarde donc si la base est vide.
if [ "$(php artisan tinker --execute='echo App\Models\Entreprise::count();' 2>/dev/null | tail -1)" = "0" ]; then
    echo "→ base vide : jeu de démonstration"
    php artisan db:seed --force --no-interaction
fi

# Les caches de configuration et de routes : ils évitent de relire et
# d'analyser les fichiers à chaque requête. Générés ici et non dans l'image,
# parce que le cache de configuration FIGE les variables d'environnement au
# moment où il est écrit — le faire à la construction y enfermerait les
# valeurs de construction, pas celles d'exécution.
echo "→ caches"
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "→ en écoute sur le port ${PORT}"
exec php artisan serve --host=0.0.0.0 --port="${PORT}"
