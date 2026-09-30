# Image PHP du projet.
#
# Pourquoi une image et pas le PHP du poste : Smart App Control (Windows 11)
# refuse d'exécuter le binaire PHP officiel, qui n'est pas signé. Le désactiver
# est irréversible sans réinstaller Windows — hors de question pour un confort
# de développement. Docker règle la question et rapproche le développement de
# la production.
FROM php:8.4-cli-alpine

# Deux familles de paquets, et c'est TOUT l'enjeu de ce fichier :
#
#   - les bibliothèques d'EXÉCUTION (libpq, icu-libs, libzip), que les
#     extensions chargent à chaque démarrage de PHP. Elles restent ;
#   - les en-têtes de COMPILATION (-dev), qui ne servent qu'à construire les
#     extensions. Elles partent, regroupées sous un nom virtuel.
#
# Une première version supprimait « postgresql-dev » sans avoir installé
# « libpq » : la compilation réussissait, l'image se construisait sans erreur,
# et pdo_pgsql refusait de se charger à l'exécution. Laravel répondait alors
# « could not find driver » — un message qui ne désigne pas la cause.
RUN apk add --no-cache libpq icu-libs libzip oniguruma \
    && apk add --no-cache --virtual .build-deps \
        postgresql-dev icu-dev libzip-dev oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip bcmath opcache \
    && apk del .build-deps

# Les réglages d'opcache et du cache de chemins. Sans eux, chaque requête
# relit des centaines de fichiers à travers le montage Windows : l'application
# répond en plusieurs secondes et paraît cassée.
COPY docker/php/php.ini /usr/local/etc/php/conf.d/factura.ini

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Le bit d'exécution est posé ici, pas hérité du poste : Git sous Windows ne
# conserve pas les permissions, et un script copié sans +x fait échouer le
# démarrage avec « permission denied ».
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

WORKDIR /app

# Le serveur de développement d'Artisan écoute sur toutes les interfaces :
# sans 0.0.0.0, il n'écouterait que la boucle locale DU CONTENEUR et resterait
# injoignable depuis Windows.
EXPOSE 8000
ENTRYPOINT ["entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
