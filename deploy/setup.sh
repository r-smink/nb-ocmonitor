#!/usr/bin/env bash
# Eerste installatie op de droplet: maakt .env en signer.env met willekeurige geheimen,
# genereert het panel-sleutelpaar en bouwt het image. Draai vanuit de repo-root: ./deploy/setup.sh
set -euo pipefail
cd "$(dirname "$0")/.."

if [ -f .env ] || [ -f signer.env ]; then
  echo "Er bestaat al een .env of signer.env. Setup stopt om niets te overschrijven." >&2
  exit 1
fi
command -v docker >/dev/null || { echo "Docker ontbreekt." >&2; exit 1; }
command -v openssl >/dev/null || { echo "openssl ontbreekt." >&2; exit 1; }

read -rp "Domein van het panel (bijv. ocm.nextbuzz.nl): " DOMAIN
read -rp "E-mailadres voor Let's Encrypt-meldingen: " ACME_EMAIL
read -rp "E-mailadres van de eerste admin: " ADMIN_EMAIL
read -rp "IP-adressen die het panel mogen openen (spatie-gescheiden, leeg = iedereen): " ADMIN_IPS

[ -n "$DOMAIN" ] && [ -n "$ADMIN_EMAIL" ] || { echo "Domein en admin-e-mail zijn verplicht." >&2; exit 1; }

APP_KEY="base64:$(openssl rand -base64 32)"
DB_PASSWORD="$(openssl rand -hex 24)"
DB_ROOT_PASSWORD="$(openssl rand -hex 24)"
ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-20)"

echo "Image bouwen (eerste keer duurt een paar minuten)…"
docker compose build app

KEYS="$(docker run --rm --entrypoint php nb-ocmonitor:latest /var/www/html/artisan ocm:keygen --dotenv)"
PUBLIC_KEY="$(printf '%s\n' "$KEYS" | sed -n 's/^OCM_PANEL_PUBLIC_KEY=//p')"
SIGNING_KEY="$(printf '%s\n' "$KEYS" | sed -n 's/^OCM_SIGNING_KEY=//p')"
[ -n "$PUBLIC_KEY" ] && [ -n "$SIGNING_KEY" ] || { echo "Sleutels genereren mislukt." >&2; exit 1; }

umask 077
cat > .env <<EOF
# NB OC Monitor (aangemaakt door deploy/setup.sh op $(date -u +%F)). Bewaar een kopie in de wachtwoordkluis:
# zonder APP_KEY zijn 2FA-geheimen en koppelcodes in de database niet meer te lezen.
DOMAIN=${DOMAIN}
ACME_EMAIL=${ACME_EMAIL}
ADMIN_ALLOW_IPS=${ADMIN_IPS:-0.0.0.0/0 ::/0}

APP_NAME="OC Monitor"
APP_ENV=production
APP_KEY=${APP_KEY}
APP_DEBUG=false
APP_URL=https://${DOMAIN}
APP_LOCALE=nl
LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=ocmonitor
DB_USERNAME=ocmonitor
DB_PASSWORD=${DB_PASSWORD}
DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}

HASH_DRIVER=argon2id
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=strict
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=log

OCM_PANEL_PUBLIC_KEY=${PUBLIC_KEY}
OCM_ADMIN_EMAIL=${ADMIN_EMAIL}
OCM_ADMIN_PASSWORD=${ADMIN_PASSWORD}
OCM_RESET_ADMIN=0
OCM_HEARTBEAT_MINUTES=15
EOF

cat > signer.env <<EOF
# ALLEEN voor de signer-container. Nooit delen, nooit in git. Kopie in de wachtwoordkluis.
OCM_SIGNING_KEY=${SIGNING_KEY}
EOF

chmod 600 .env signer.env

cat <<EOF

Klaar. Start het panel met:

  docker compose up -d --build

Daarna: https://${DOMAIN}
  E-mail:     ${ADMIN_EMAIL}
  Wachtwoord: ${ADMIN_PASSWORD}
  Bij de eerste login stel je tweestapsverificatie in.

Zet .env en signer.env nu in de wachtwoordkluis.
Wijzig na de eerste login je wachtwoord via Account. Het tijdelijke wachtwoord blijft in .env staan
(het wordt alleen gebruikt als er nog geen gebruikers zijn, of met OCM_RESET_ADMIN=1).
EOF
