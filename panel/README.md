# NB OC Monitor: panel

Laravel 13 met server-rendered Blade in de vormgeving van ServerDash (Next Buzz Design System, standaard donker thema). Er is geen build-stap: de CSS, JS en lettertypes staan in `public/`.

## Onderdelen

| Onderdeel | Waar |
|---|---|
| Login: wachtwoord (Argon2id) en verplichte TOTP, rate limiting | `app/Http/Controllers/Auth`, `app/Ocm/TwoFactor.php` |
| Sites, koppelcodes, plugin-download | `SiteController`, `PluginDownloadController`, `app/Ocm/PluginPackage.php` |
| API voor de client-plugin: `POST /api/pair` en `POST /api/sites/{id}/events` | `app/Http/Controllers/Api` |
| Signer (enige houder van de private sleutel): policy, ondertekenen, verifiëren | `php artisan ocm:signer`, `app/Ocm/Signer` |
| Planning: heartbeat elke 5 min (per site eens per 15 min), inventaris om 03:15, opruimen elk uur | `routes/console.php` |
| Audit log met hash-keten | `app/Ocm/Activity.php`, `php artisan ocm:verify-log` |
| Vormgeving | `public/css/ds` (tokens), `public/css/serverdash.css` (kopie uit ServerDash), `public/css/ocm.css` (aanvullingen) |

## Lokaal draaien

Composer staat in `C:\Devtools\composer`, en de extensies laden via `PHP_INI_SCAN_DIR`:

```bash
export PHP_INI_SCAN_DIR=C:/Devtools/composer/conf.d
composer install
php artisan migrate
php artisan ocm:keygen            # sleutels in .env zetten (lokaal mag de signing key in .env)
php artisan ocm:bootstrap-admin   # OCM_ADMIN_EMAIL/OCM_ADMIN_PASSWORD uit .env
php artisan serve
php artisan ocm:signer            # in een tweede terminal
php artisan test
```

Na een wijziging in `../protocol/src`: `rm -rf vendor/nextbuzz/ocm-protocol && composer install`. Het pakket wordt gekopieerd, niet gesymlinkt, zodat de Docker-build gelijk werkt.

Productie: zie [docs/DEPLOY-DIGITALOCEAN.md](../docs/DEPLOY-DIGITALOCEAN.md).
