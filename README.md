# NB OC Monitor

Eigen beheerplatform voor de WordPress-sites van Next Buzz (in de geest van InfiniteWP): updates, backups, gebruikersbeheer, activity log, PageSpeed, malwarescan en klantrapportage in Next Buzz-huisstijl.

| Map | Inhoud | Status |
|---|---|---|
| [`protocol/`](protocol/) | Ondertekend protocol (Ed25519) tussen panel en plugin, met testvectoren | Fase 0, klaar |
| [`docs/`](docs/) | [Protocolspecificatie](docs/protocol.md), [dreigingsmodel](docs/threat-model.md) | Fase 0, klaar |
| [`client-plugin/`](client-plugin/nb-ocmonitor-client/) | WordPress-plugin `nb-ocmonitor-client`: koppelen, heartbeat, inventaris, registratie van alle wijzigingen | Fase 1, plugin-MVP klaar (nog niet getest op een echte WP-installatie) |
| `panel/` | Laravel + Livewire-dashboard (vormgeving en donker thema gelijk aan ServerDash) en signer | Fase 1 |
| `deploy/` | docker-compose, reverse proxy | Fase 1 |

## Protocoltests draaien

De library heeft geen dependencies en draait op PHP ≥ 7.4 met ext-sodium:

```bash
cd protocol
php -d extension=sodium tests/run.php
```

Staat sodium niet aan in `php.ini` en ligt de extensiemap niet op de standaardplek, geef die dan mee, bijvoorbeeld:
`php -d extension_dir=C:/Devtools/php/ext -d extension=sodium tests/run.php`.

Genereer `tests/vectors.json` alleen opnieuw (`tests/generate-vectors.php`) bij een bewuste protocolwijziging. De vectoren zijn het contract tussen panel en plugin.

## Hoe de library gebruikt wordt

- **Panel:** via een Composer path-repository (`nextbuzz/ocm-protocol`).
- **Plugin:** de library wordt bij de build naar `client-plugin/nb-ocmonitor-client/lib/protocol/` gekopieerd (WordPress-plugins hebben geen Composer tijdens runtime). Een CI-check vergelijkt de kopie met het origineel.
