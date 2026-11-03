# NB OC Monitor: productie-image (web, signer en scheduler gebruiken hetzelfde image).
# Bouwen vanuit de repo-root:  docker compose build

# ---------- 1. PHP-dependencies ----------
FROM composer:2 AS vendor
WORKDIR /build
COPY protocol /build/protocol
COPY panel/composer.json panel/composer.lock /build/panel/
WORKDIR /build/panel
# Platform-eisen worden in het runtime-image gecontroleerd; dit image dient alleen om te downloaden.
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY panel /build/panel
RUN composer dump-autoload --optimize --no-dev --no-scripts

# ---------- 2. Runtime ----------
FROM serversideup/php:8.4-fpm-nginx

USER root
# gd: afbeeldingen in rapporten (mPDF). qpdf: geüploade PDF-achtergronden omzetten naar een vorm die FPDI kan lezen.
RUN install-php-extensions intl gd \
    && apt-get update && apt-get install -y --no-install-recommends qpdf && rm -rf /var/lib/apt/lists/*

ENV OCM_CLIENT_PLUGIN_PATH=/var/www/client-plugin/nb-ocmonitor-client \
    PHP_OPCACHE_ENABLE=1 \
    AUTORUN_ENABLED=false \
    LOG_CHANNEL=stderr

COPY --chown=www-data:www-data --from=vendor /build/panel /var/www/html
COPY --chown=www-data:www-data client-plugin/nb-ocmonitor-client /var/www/client-plugin/nb-ocmonitor-client
COPY --chmod=755 deploy/entrypoint.d/ /etc/entrypoint.d/

USER www-data
