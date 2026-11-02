# NB OC Monitor Client (WordPress-plugin)

Koppelt een WordPress-site aan het NB OC Monitor-panel. Versie 0.1.0, fase 1 (MVP): koppelen, site-info als heartbeat, inventaris, en registratie van **alle** wijzigingen.

Vereist WordPress 6.0+ en PHP 7.4+. Zonder ext-sodium gebruikt de plugin het `sodium_compat` dat WordPress meelevert.

## Koppelen

1. Maak in het panel een site aan. Je krijgt een koppelcode (`nbocm1.…`, 15 minuten geldig).
2. Plak de code op de site onder **Extra → NB OC Monitor**, of gebruik WP-CLI: `wp nb-ocm pair <code>`.
3. De site maakt een eigen Ed25519-sleutelpaar en stuurt alleen de publieke sleutel naar het panel.

De site moet via HTTPS bereikbaar zijn. Voor lokaal testen kun je `define('NB_OCM_ALLOW_HTTP', true);` zetten.

## Constanten in wp-config.php

| Constante | Werking |
|---|---|
| `NB_OCM_DISABLED` | Noodstop: de plugin registreert niets meer. Het endpoint bestaat dan niet. |
| `NB_OCM_SITE_KEY` | Private site-sleutel (base64url) uit wp-config in plaats van uit de database. |
| `NB_OCM_ALLOW_HTTP` | Alleen voor lokaal testen: koppelen zonder HTTPS toestaan. |

## Endpoint en commands

- Endpoint: `POST /wp-json/nb-ocm/v1/cmd` (of `?rest_route=/nb-ocm/v1/cmd`). Elk verzoek moet ondertekend zijn volgens [docs/protocol.md](../../docs/protocol.md).
- Een niet-geverifieerd verzoek krijgt dezelfde 404 als een onbekende route.
- Commands in fase 1:

| Command | Scope | Doel |
|---|---|---|
| `site.info` | read | Heartbeat: versies en `last_event_seq` |
| `site.inventory` | read | Core, plugins, mu-plugins, thema's, beschikbare updates, gebruikers per rol en omgeving. `{"refresh": true}` controleert eerst bij wordpress.org. Detecteert ook wijzigingen buiten WordPress om. |
| `events.pull` | events | `{"after_seq": n, "limit": ≤500}` |
| `events.ack` | events | `{"up_to_seq": n}`. Bevestigde events worden na 30 dagen lokaal opgeruimd. |

## Registratie van wijzigingen (plan §2.7)

Vastgelegd in `{prefix}nb_ocm_events`, met een oplopend `seq`. Nieuwe events worden direct en non-blocking naar `POST {panel}/api/sites/{site_id}/events` gestuurd, ondertekend met de site-sleutel. Het panel vult gemiste events aan via `events.pull`.

- **Updates en installaties** van core, plugins, thema's en vertalingen (met oude → nieuwe versie). Mislukte auto-updates worden ook vastgelegd.
- **Plugins en thema's:** activeren, deactiveren, verwijderen en wisselen van thema.
- **Gebruikers:** aangemaakt, rol gewijzigd, verwijderd, e-mail of wachtwoord gewijzigd (zonder waarden), logins van beheerders, en mislukte logins als telling per uur.
- **Kritieke opties:** `siteurl`, `home`, `admin_email`, `users_can_register` en `default_role`.
- **Wijzigingen buiten WordPress om** (FTP, deploy, restore) via een versie-snapshot, twee keer per dag en bij elke inventaris: `component.changed_externally`.
- **Bron per event:** `nb-ocm` (met `job_id`), `wp-admin`, `wp-cli`, `auto-update`, `cron`, `rest`, `wp-login` of `unknown`.

## Tests

```bash
php -d extension=sodium tests/run.php
```

Deze tests dekken de kern: verificatie, replay, scopes, het body-schema en ondertekende antwoorden, zonder WordPress. De WordPress-laag (hooks, REST, admin-pagina, WP-CLI) moet nog op een echte WordPress-installatie worden getest (docker-compose in fase 1).

## Bekende aandachtspunten

- Security-plugins die de REST API voor niet-ingelogde bezoekers blokkeren (bijvoorbeeld "Disable REST API" en sommige instellingen van iThemes of Solid Security) moeten `nb-ocm/v1` toestaan.
- Multisite wordt nog niet ondersteund. De plugin blijft dan inactief en toont een melding.
- Muterende commands (updates, gebruikers, backups) komen in fase 2 en 4. Die krijgen ook idempotentie op `job_id`.
