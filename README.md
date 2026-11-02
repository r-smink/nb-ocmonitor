# NB OC Monitor

Eigen beheerplatform voor de WordPress-sites van Next Buzz (in de geest van InfiniteWP): updates, backups, gebruikersbeheer, activity log, PageSpeed, malwarescan en klantrapportage in Next Buzz-huisstijl.

| Map | Inhoud | Status |
|---|---|---|
| [`protocol/`](protocol/) | Ondertekend protocol (Ed25519) tussen panel en plugin, met testvectoren | Klaar (fase 0) |
| [`client-plugin/`](client-plugin/nb-ocmonitor-client/) | WordPress-plugin `nb-ocmonitor-client`: koppelen, heartbeat, inventaris, registratie van alle wijzigingen | MVP (fase 1). Nog te testen op een echte WP-site |
| [`panel/`](panel/) | Laravel-panel in ServerDash-stijl (standaard donker): login met verplichte 2FA, sites koppelen, plugin-download, signer, wijzigingslog, audit log | MVP (fase 1) |
| [`deploy/`](deploy/) + [`docker-compose.yml`](docker-compose.yml) | Docker-opzet: Caddy (HTTPS), app, signer, scheduler, MySQL | Klaar voor een droplet. Lokaal niet getest (geen Docker) |
| [`docs/`](docs/) | [Protocol](docs/protocol.md), [dreigingsmodel](docs/threat-model.md), [installatie op DigitalOcean](docs/DEPLOY-DIGITALOCEAN.md) | |

## Installeren op een server

Zie [docs/DEPLOY-DIGITALOCEAN.md](docs/DEPLOY-DIGITALOCEAN.md). Kort samengevat:

```bash
git clone … /opt/nb-ocmonitor && cd /opt/nb-ocmonitor
bash deploy/setup.sh          # maakt .env + signer.env, sleutels en het eerste admin-account
docker compose up -d --build
```

## Tests

```bash
php -d extension=sodium protocol/tests/run.php                           # protocol (46)
php -d extension=sodium client-plugin/nb-ocmonitor-client/tests/run.php  # plugin-kern (19)
cd panel && php artisan test                                              # panel (27)
php scripts/sync-protocol.php --check                                     # plugin-kopie van het protocol actueel?
```

Op deze ontwikkelmachine laden de PHP-extensies via `PHP_INI_SCAN_DIR=C:/Devtools/composer/conf.d` (zie [panel/README.md](panel/README.md)).

Genereer `protocol/tests/vectors.json` alleen opnieuw (`tests/generate-vectors.php`) bij een bewuste protocolwijziging. De vectoren zijn het contract tussen panel en plugin.

## Hoe de protocol-library gebruikt wordt

- **Panel:** via een Composer path-repository (`nextbuzz/ocm-protocol`, gekopieerd naar `vendor`).
- **Plugin:** `scripts/sync-protocol.php` kopieert de library naar `client-plugin/nb-ocmonitor-client/lib/protocol/`, omdat WordPress-plugins tijdens runtime geen Composer hebben.
