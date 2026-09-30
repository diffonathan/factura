#!/bin/sh
# Démarrage en production.
#
# L'ordre compte : on migre AVANT de servir. Un conteneur qui répond avant que
# le schéma soit à jour rend des erreurs pendant quelques secondes, et le
# contrôle de santé peut le déclarer bon alors qu'il ne l'est pas.
set -e

# ---------------------------------------------------------------------------
# Les variables, vérifiées AVANT de faire quoi que ce soit.
#
# Sans ces contrôles, une variable mal saisie fait échouer `artisan migrate`,
# le script s'arrête sur `set -e`, et l'hébergeur n'affiche que « le conteneur
# s'est arrêté ». Le message de Laravel se perd, et on cherche dans la
# mauvaise direction pendant une demi-heure — c'est exactement ce qui est
# arrivé.
#
# Mieux vaut dire précisément ce qui manque, en une ligne lisible.
# ---------------------------------------------------------------------------

erreur() {
    echo ""
    echo "════════════════════════════════════════════════════════════════"
    echo "  CONFIGURATION INCOMPLÈTE — l'application ne peut pas démarrer"
    echo "════════════════════════════════════════════════════════════════"
    echo ""
    echo "  $1"
    echo ""
    echo "  Corrigez la variable chez votre hébergeur, puis redéployez."
    echo "════════════════════════════════════════════════════════════════"
    echo ""
    exit 1
}

case "${APP_KEY}" in
    "")
        erreur "APP_KEY est vide.
  Produisez-en une avec : php artisan key:generate --show
  et collez la ligne ENTIÈRE, « base64: » compris." ;;
    base64:*)
        : ;;
    *)
        erreur "APP_KEY ne commence pas par « base64: ».
  Laravel décode la clé grâce à ce préfixe. Sans lui, elle fait 44 octets
  au lieu de 32 et le chiffrement refuse de démarrer.
  Valeur reçue : ${APP_KEY%%${APP_KEY#?????}}… (début seulement)" ;;
esac

case "${DB_URL}" in
    "")
        erreur "DB_URL est vide.
  Attendu l'adresse COMPLÈTE de la base, par exemple :
  postgresql://utilisateur:motdepasse@hote.neon.tech/factura?sslmode=require" ;;
    postgres://*|postgresql://*|pgsql://*)
        : ;;
    *)
        erreur "DB_URL n'est pas une adresse de base de données.
  Reçu : « ${DB_URL} »
  Attendu l'adresse COMPLÈTE, par exemple :
  postgresql://utilisateur:motdepasse@hote.neon.tech/factura?sslmode=require
  « /factura » n'est que la FIN de l'adresse — le nom de la base." ;;
esac

echo "→ configuration vérifiée"

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
