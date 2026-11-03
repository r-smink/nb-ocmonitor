# NB OC Monitor Client (WordPress-plugin)

Koppelt een WordPress-site aan het NB OC Monitor-panel. Versie 0.3.0: koppelen, site-info als heartbeat, inventaris, registratie van **alle** wijzigingen, updates van plugins, thema's, vertalingen en WordPress vanuit het panel, en inlogbeveiliging.

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
- Commands:

| Command | Scope | Doel |
|---|---|---|
| `site.info` | read | Heartbeat: versies en `last_event_seq` |
| `site.inventory` | read | Core, plugins, mu-plugins, thema's, beschikbare updates, gebruikers per rol en omgeving. `{"refresh": true}` controleert eerst bij wordpress.org. Detecteert ook wijzigingen buiten WordPress om. |
| `events.pull` | events | `{"after_seq": n, "limit": ≤500}` |
| `events.ack` | events | `{"up_to_seq": n}`. Bevestigde events worden na 30 dagen lokaal opgeruimd. |
| `update.plugin` | updates | `{"plugins": ["map/bestand.php", …]}`, maximaal 10 |
| `update.theme` | updates | `{"themes": ["stylesheet", …]}`, maximaal 10 |
| `update.core` | updates | Geen argumenten: de update die WordPress zelf aanbiedt |
| `update.translations` | updates | Geen argumenten |

## Scopes

`read`, `events` en `updates` staan altijd aan (sinds 0.3.0). Een beheerder van de site kan updates vanuit het panel niet meer uitzetten; wie de koppeling wil stoppen, ontkoppelt de site of zet `NB_OCM_DISABLED` in wp-config.php.

## Inlogbeveiliging

Instellingen onder **Extra → NB OC Monitor → Inlogbeveiliging**, of met `wp nb-ocm login-security`. Standaard aan:

- Blokkade per IP-adres na 5 mislukte pogingen binnen 15 minuten, voor 30 minuten. Na drie blokkades op een dag: 24 uur. Een geblokkeerd IP komt er ook met het juiste wachtwoord niet in.
- Het echte IP-adres achter Cloudflare (alleen als het verzoek echt van Cloudflare komt) of achter een proxy op de server. Een `X-Forwarded-For` van buitenaf wordt genegeerd.
- Uitzonderingen per IP of CIDR-range (bijv. het kantoor). Opheffen: `wp nb-ocm login-security --unlock`.
- XML-RPC-login uit, behalve als Jetpack actief is. Ook `system.multicall` en pingbacks uit.
- Gebruikersnamen afgeschermd voor bezoekers die niet zijn ingelogd: geen `/wp-json/wp/v2/users`, geen `?author=1`, geen users-sitemap.

Inloggen via wp-login.php, de blokeditor en de WordPress-app met een applicatiewachtwoord werken gewoon. Mislukte pogingen komen per uur als `login.failed_summary` in het wijzigingslog, met het kanaal (inlogpagina, XML-RPC, REST) en het aantal blokkades. IP-adressen worden alleen ingekort opgeslagen (/24 of /48).

## Updates (plan §4.2)

- Het panel kiest alleen **wat** er bijgewerkt wordt. Het pakket komt altijd uit de update-transient van de site zelf (wordpress.org of de updater van een premium-plugin). Het panel kan dus geen eigen zip of URL meesturen.
- Per plugin of thema: eerst een kopie van de map naar `wp-content/nb-ocm-rollback/` (afgeschermd met `.htaccess` en `index.php`), dan de update via `Plugin_Upgrader`/`Theme_Upgrader`, en daarna een health check: een loopback-verzoek naar de homepage.
- Werkte de homepage vóór de update wel en geeft hij daarna een 5xx, dan zet de plugin de oude map terug (`status: rolled_back`) en legt `plugin.rolled_back` vast. De kopie wordt daarna altijd verwijderd.
- Lukt de loopback niet (sommige hosts blokkeren dat), dan beslist de plugin niet op basis van de health check. Een rollback vindt dan alleen plaats als de map na een mislukte update verdwenen is.
- Voor WordPress zelf is er geen automatische rollback. Een mislukte health check wordt gemeld als `health_failed`.
- Met `DISALLOW_FILE_MODS` of zonder schrijfrechten (FTP-gegevens nodig) weigert de plugin de update met een duidelijke fout.

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
- Gebruikers- en backup-commands komen in latere fases.
- Updates via de upgraders zijn nog niet getest op een echte WordPress-installatie. Test eerst op een interne site, ook met een premium-plugin (die levert zijn pakket soms alleen aan ingelogde beheerders).
