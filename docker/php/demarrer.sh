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

# Le même encadré, mais pour un refus venu de la base : ce n'est plus une
# variable manquante, c'est une valeur que le serveur rejette. Le titre doit
# le dire, sinon on retourne vérifier ce qui est déjà correct.
refus_base() {
    echo ""
    echo "════════════════════════════════════════════════════════════════"
    echo "  LA BASE DE DONNÉES REFUSE LA CONNEXION"
    echo "════════════════════════════════════════════════════════════════"
    echo ""
    echo "  $1"
    echo ""
    echo "════════════════════════════════════════════════════════════════"
    echo ""
    exit 1
}

case "${APP_KEY}" in
    "")
        erreur "APP_KEY est vide.
  Produisez-en une avec : php artisan key:generate --show
  et collez la ligne ENTIÈRE, « base64: » comprise." ;;
    base64:*)
        : ;;
    *)
        erreur "APP_KEY ne commence pas par « base64: ».
  Laravel décode la clé grâce à ce préfixe. Sans lui, elle fait 44 octets
  au lieu de 32 et le chiffrement refuse de démarrer.
  Valeur reçue : ${APP_KEY%%${APP_KEY#?????}}… (début seulement)" ;;
esac

# ---------------------------------------------------------------------------
# La base : DEUX façons de la décrire, et c'est délibéré.
#
# `DB_URL` tient tout dans une variable — pratique, et c'est ce que les
# hébergeurs proposent à copier. Mais une adresse est ANALYSÉE : si le mot de
# passe contient @ : / ? # % ou &, l'analyseur coupe au premier et Laravel
# envoie un mot de passe tronqué. Le serveur répond « mot de passe refusé »
# alors que la valeur collée est la bonne — un échec qui accuse la mauvaise
# chose.
#
# Les cinq variables séparées ne passent par aucun analyseur. Elles sont la
# sortie de secours, et il faut qu'elle existe AVANT d'en avoir besoin.
# ---------------------------------------------------------------------------

if [ -n "${DB_URL}" ]; then
    case "${DB_URL}" in
        postgres://*|postgresql://*|pgsql://*)
            : ;;
        *)
            erreur "DB_URL n'est pas une adresse de base de données.
  Reçu : « ${DB_URL} »
  Attendu l'adresse COMPLÈTE, par exemple :
  postgresql://utilisateur:motdepasse@hote.neon.tech/factura?sslmode=require
  « /factura » n'est que la FIN de l'adresse — le nom de la base." ;;
    esac
    echo "→ base décrite par DB_URL"
elif [ -n "${DB_HOST}" ]; then
    [ -n "${DB_DATABASE}" ] || erreur "DB_HOST est renseigné mais pas DB_DATABASE (le nom de la base, par exemple « factura »)."
    [ -n "${DB_USERNAME}" ] || erreur "DB_HOST est renseigné mais pas DB_USERNAME."
    [ -n "${DB_PASSWORD}" ] || erreur "DB_HOST est renseigné mais pas DB_PASSWORD."

    # Sans consigne, le pilote PostgreSQL se contente de « prefer » : il tente
    # le chiffrement, et s'en passe si le serveur ne le propose pas. Un mot de
    # passe peut donc partir en clair sans que rien ne le signale. Sur une base
    # jointe par l'internet, ce n'est pas un réglage acceptable par défaut.
    if [ -z "${DB_SSLMODE}" ]; then
        DB_SSLMODE=require
        export DB_SSLMODE
        echo "→ DB_SSLMODE absent : forcé à « require »"
    fi
    echo "→ base décrite par DB_HOST / DB_DATABASE / DB_USERNAME / DB_PASSWORD"
else
    erreur "Aucune base de données n'est configurée.
  Deux façons, au choix :
  - DB_URL : l'adresse complète, par exemple
    postgresql://utilisateur:motdepasse@hote.neon.tech/factura?sslmode=require
  - DB_HOST + DB_PORT + DB_DATABASE + DB_USERNAME + DB_PASSWORD
    À préférer si le mot de passe contient @ : / ? # % ou & — ces
    caractères cassent l'analyse d'une adresse."
fi

echo "→ configuration vérifiée"

# ---------------------------------------------------------------------------
# Les migrations, avec leur sortie CONSERVÉE.
#
# La sortie est gardée pour être relue : « l'authentification a échoué » et
# « l'hôte est introuvable » ne se corrigent pas au même endroit, et un
# journal d'hébergeur tronque volontiers la pile d'exception juste avant la
# ligne qui compte. Autant traduire nous-mêmes.
# ---------------------------------------------------------------------------
echo "→ migrations"

set +e
php artisan migrate --force --no-interaction > /tmp/migrations.log 2>&1
code_migration=$?
set -e

cat /tmp/migrations.log

if [ "${code_migration}" -ne 0 ]; then
    journal="$(cat /tmp/migrations.log)"

    case "${journal}" in
        *"password authentication failed"*|*"authentification par mot de passe"*)
            refus_base "PostgreSQL a répondu, mais refuse le mot de passe.

  L'adresse est donc bonne : le serveur est joignable et la base existe.
  C'est l'authentification qui échoue. Trois causes, par fréquence :

  1. LE MOT DE PASSE A ÉTÉ RÉINITIALISÉ chez l'hébergeur de la base.
     Celui inscrit dans la configuration est l'ancien. Reprenez la chaîne
     affichée APRÈS la réinitialisation, en entier, et recollez-la.

  2. LE MOT DE PASSE CONTIENT UN CARACTÈRE RÉSERVÉ : @ : / ? # % ou &.
     Dans une adresse, ces caractères ont un sens. L'analyseur coupe au
     premier et envoie un mot de passe tronqué — le serveur le refuse, et
     l'erreur accuse le mot de passe alors que le fautif est le format.

     Remède : ne pas utiliser DB_URL. Supprimez-la et renseignez à la
     place les cinq variables séparées :
       DB_HOST      l'hôte seul, sans « postgresql:// » ni « / »
       DB_PORT      5432
       DB_DATABASE  factura
       DB_USERNAME  l'utilisateur
       DB_PASSWORD  le mot de passe, tel quel, sans encodage
       DB_SSLMODE   require
     Aucune analyse n'a lieu : le mot de passe passe intact.

  3. DES GUILLEMETS ONT ÉTÉ COPIÉS avec la valeur. Chez un hébergeur, le
     champ prend la valeur brute : les guillemets en feraient partie."
            ;;
        *"could not translate host name"*|*"Name or service not known"*)
            # Ce cas a DEUX causes très différentes, et la seconde est
            # contre-intuitive : ce n'est pas l'hôte qui est faux, c'est le
            # MOT DE PASSE qui a déplacé l'hôte.
            #
            # Avec un mot de passe « a@b/c », l'analyseur d'adresse coupe au
            # premier @ : il retient « a » comme mot de passe, puis lit « b »
            # comme nom de machine et avale le reste comme nom de base.
            # Laravel part alors chercher un serveur appelé « b ». Mesuré, pas
            # supposé — c'est ce qui arrive avec un vrai mot de passe Neon
            # contenant un @.
            if [ -n "${DB_URL}" ]; then
                refus_base "L'hôte de la base est introuvable — et le coupable est peut-être
  le mot de passe.

  Deux causes possibles :

  1. LE MOT DE PASSE CONTIENT @ : / ? # % ou &.
     L'adresse est alors découpée au mauvais endroit. Avec un mot de passe
     « a@b/c », l'analyseur retient « a » comme mot de passe, prend « b »
     pour le nom du serveur, et le reste pour le nom de la base. D'où un
     hôte introuvable qui ne figure nulle part dans ce que vous avez collé.

     Remède : supprimez DB_URL et renseignez les cinq variables séparées,
     qui ne passent par aucun analyseur :
       DB_HOST      l'hôte seul, sans « postgresql:// » ni « / »
       DB_PORT      5432
       DB_DATABASE  factura
       DB_USERNAME  l'utilisateur
       DB_PASSWORD  le mot de passe, tel quel, sans encodage
       DB_SSLMODE   require

  2. L'ADRESSE A ÉTÉ TRONQUÉE À LA COPIE, ou une espace s'est glissée au
     bout du nom."
            else
                refus_base "L'hôte de la base est introuvable.

  « ${DB_HOST} » ne correspond à aucun serveur. Vérifiez que le nom n'a pas
  été tronqué à la copie, qu'aucune espace ne traîne au bout, et qu'il ne
  contient ni « postgresql:// » ni « / » — DB_HOST attend l'hôte SEUL."
            fi
            ;;
        *"does not exist"*)
            refus_base "Le serveur répond et accepte l'utilisateur, mais la base nommée
  n'existe pas. Créez-la chez votre hébergeur de base, ou corrigez son
  nom — ici « factura »."
            ;;
        *"Connection refused"*|*"timeout expired"*|*"Operation timed out"*)
            refus_base "Le serveur de base ne répond pas.

  Soit l'hôte ou le port sont faux, soit la base est en veille. Un
  hébergement gratuit endort sa base : réessayez une fois, le réveil
  prend quelques secondes."
            ;;
        *)
            # Cause inconnue : le journal ci-dessus est la seule vérité, on
            # n'y ajoute pas une interprétation inventée.
            echo ""
            echo "  Les migrations ont échoué. Le message exact est au-dessus."
            exit 1
            ;;
    esac
fi

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

# `--no-reload` n'est pas un détail de confort : sans lui, `artisan serve`
# surveille les fichiers pour redémarrer quand ils changent, et ce guetteur
# INTERDIT les processus multiples. PHP_CLI_SERVER_WORKERS=4 était donc lettre
# morte — un seul processus servait tout, et le journal le disait :
#
#   WARN  Unable to respect the `PHP_CLI_SERVER_WORKERS` environment variable
#         without the `--no-reload` flag. Only creating a single server.
#
# En production les fichiers ne changent jamais : surveiller leurs dates coûte
# du temps et ne sert personne. On rend donc les quatre processus effectifs,
# ce qui évite qu'une requête lente bloque toutes les autres — dont le
# contrôle de santé de l'hébergeur, qui conclurait à une panne.
exec php artisan serve --host=0.0.0.0 --port="${PORT}" --no-reload
