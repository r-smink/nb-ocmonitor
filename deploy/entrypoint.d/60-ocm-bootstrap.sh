#!/bin/sh
# Alleen in de app-container (OCM_BOOTSTRAP=true): eerste admin aanmaken of resetten vanuit .env.
# Draait na de Laravel-automations van het image (migraties).
set -e
if [ "${OCM_BOOTSTRAP:-false}" = "true" ]; then
  php /var/www/html/artisan ocm:bootstrap-admin --no-interaction
fi
