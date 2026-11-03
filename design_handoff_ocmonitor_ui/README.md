# Handoff: OC Monitor — gebruiksvriendelijkere UI (richting "Werkplek")

## Overview
Herontwerp van het **NB OC Monitor**-panel (WordPress-beheerplatform: updates, back-ups,
onderhoudscontracten, PageSpeed, kwetsbaarheden, rapportage) om het gebruiksvriendelijker te maken.
De gekozen richting ("Werkplek", in het conversatie-optieboard variant **2a**) voegt toe:
een **opgeruimde, gegroepeerde zijbalk**, een **KPI-samenvatting bovenaan**, **overzichtskaarten i.p.v.
dichte tabellen** waar dat helpt, en een **actieknop per rij**. Licht én donker thema (toggle).

## About the Design Files
De bestanden in dit pakket zijn **ontwerpreferenties, gemaakt in HTML** (streaming "Design Components").
Het zijn prototypes die de bedoelde look & behavior tonen — **geen productiecode om 1-op-1 te kopiëren**.
De opdracht is om deze ontwerpen te **herbouwen in de bestaande codebase** van het panel:
**Laravel + Blade** (`panel/resources/views/*`), gestyled met de al aanwezige
`public/css/serverdash.css`, `public/css/ocm.css` en de design-tokens in `public/css/ds/tokens/*`.
Gebruik de bestaande patronen (Blade-layout `layouts/app.blade.php`, `.panel`, `.btn`, `.chip`,
`.stat`, `.tabs-nav`, `.table-wrap`, enz.) — niet de HTML uit dit pakket rechtstreeks shippen.

Bron-repo: **r-smink/nb-ocmonitor**, branch `main`, map `panel/`.

## Fidelity
**High-fidelity.** Definitieve kleuren, typografie, spacing en interacties volgen de Next Buzz
design-tokens die al in de repo staan. Neem spacing/rondingen/schaduwen over via de bestaande
CSS-variabelen; verzin geen nieuwe waarden.

## Design Tokens (al aanwezig in `public/css/ds/tokens/`)
- **Merkrood:** `--red-500 #E22830` (hover `--red-600 #C41E25`); spaarzaam, voor CTA's/accenten.
- **Status:** ok `--success-500 #1F9D55`, warn `--warning-500 #E8A317`, kritiek = rood.
  Zachte achtergronden: ok `#E8F6EE`, warn `#FBF1DA`, crit `#FDECEC` (donker: `#14321f`, `#2a2410`, `#2c1416`).
- **Ink/tekst (licht):** strong `#161616`, default `#282828`, muted `#585858`, subtle `#767674`.
- **Oppervlakken (licht):** page `#FFFFFF`, alt `#F7F6F3`, sunken `#EFEEEA`, card `#FFFFFF`.
- **Donker thema** (`[data-theme="dark"]` op `<html>`, al in serverdash.css): page `#1b1a17`,
  alt `#141310`, card `#26241f`, sunken `#302d27`, borders `#38342c/#454036`, tekst `#F7F6F3/#E7E6E1/#A9A7A1`.
- **Type:** koppen **Schibsted Grotesk** 700–800, tracking `-0.02em`; body **Hanken Grotesk** 400–500.
- **Radius:** card `--radius-lg 20px`, md 14px, sm 10px; **knoppen zijn pillen** (`--radius-pill`).
- **Schaduw:** `--shadow-sm`, hover `--shadow-lg`; rode CTA-gloed `--shadow-brand`.
- **Iconen:** Lucide-stijl, ~1.75–2px lijn (in het prototype als inline SVG getekend).
- **Spacing:** 8px-ritme (`--space-*`), container `1240px`.

## Navigatie (belangrijkste wijziging)
De huidige lange horizontale topbar-nav wordt een **vaste linker zijbalk, gegroepeerd**:
- **Monitoring:** Overzicht · Sites · PageSpeed · Kwetsbaarheden (badge 3)
- **Onderhoud:** Updates (badge 42) · Klanten · Rapporten · Versies
- **Beheer:** Licenties · WP-gebruikers · Activiteit · Instellingen (portalgebruikers)
Zoekveld bovenaan, verbindingsstatus onderaan. Actief item: `--red-50` achtergrond + strong tekst.
Topbar behoudt: licht/donker-toggle, meldingsbel, "+ Site", accountknop.
Badges tonen aantallen (updates/kwetsbaarheden); toon ze alleen als het aantal > 0.

## Screens / Views
Elke pagina = de bestaande Blade-route, nu in de Werkplek-stijl. Patroon per pagina:
_paginakop (titel + 1 regel uitleg) → KPI/statuschips → inhoud als kaarten/lijst met acties._

1. **Overzicht** (`dashboard.blade.php`) — 4 KPI-kaarten (Beveiliging, Updates, Sites met problemen,
   PageSpeed) met een gekleurde bovenrand per status. "Aandachtspunten" als **takenlijst** in twee
   groepen ("Nu oplossen" kritiek / "Deze week" waarschuwing), elke rij met categorie-icoon, site,
   melding en een actieknop (Bekijken/Bijwerken/Verlengen…). Daaronder 2 widgets: "Meest voorkomende
   updates" (met "Bijwerken") en "Recente wijzigingen".
2. **Sites** (`sites/index.blade.php`) — statuschips (kritiek/aandacht/in orde/niet gekoppeld) +
   **kaart per site** met status-stip, klantbadge, probleemregel, WP/PHP/updates/status en "Openen".
3. **Klanten** (`customers/index.blade.php` + `show`) — **onderhoudskalender** (maandgrid; blauw gepland,
   groen afgerond, rood achterstallig) naast "Eerstvolgend onderhoud"; daaronder klantenlijst met
   contract, rapportstatus en "Updates starten (N)".
4. **Updates** (`updates/index.blade.php`) — chips (plugins/thema's/WordPress); "Per plugin en thema"
   als kaarten met "Bijwerken op N sites" (beveiligingsbadge waar van toepassing); "Updates per site"
   als rijen met per-rij "Bijwerken".
5. **PageSpeed** (`pagespeed/index.blade.php`) — **scorekaart per site**: ronde mobiel/desktop-scores
   (groen ≥90, oranje ≥50, rood <50) + a11y/best-practices/SEO + LCP/CLS + "Meten".
6. **Kwetsbaarheden** (`vulnerabilities/index.blade.php`) — chips hoog/gemiddeld/laag; gerangschikte
   lijst met CVSS-score-badge, site, onderdeel+versie, titel+CVE, fix-versie en "Bijwerken".
7. **Versies** (`versions/index.blade.php`) — PHP- en WordPress-distributie als chips + nette
   per-site-tabel (PHP/WP met kleur bij EOL/verouderd, plugins, updates, thema, DB-grootte).
8. **Licenties** (`licenses/index.blade.php`) — kaart per product: installaties met statusbadge
   (actief/niet actief), bulk "Activeren op N site(s)", en sleutels (gemaskeerd, seats, verloopdatum).
9. **WP-gebruikers** (`wp-users/index.blade.php`) — totalen (beheerders/zonder 2FA/geblokkeerd),
   2FA-beleidkaart ("Instellen op alle sites") + gebruikerstabel met 2FA-badge.
10. **Rapporten** (`reports/index.blade.php`) — opmaakformulier (naam/titel/inleiding/voettekst,
    kleurstalen, fonts, marges) naast assets (logo/achtergronden/fonts) + "Voorbeeld".
11. **Activiteit** (`activity/index.blade.php`) — tabs "Wijzigingen op sites" / "Audit log panel";
    tabellen met bron-badge (OC Monitor/site) en hash-keten-notitie.
12. **Instellingen → Portalgebruikers** (`users/index.blade.php`) — tabs + gebruikerstabel
    (rol-badge, 2FA) + "Gebruiker toevoegen".

## Interactions & Behavior
- **Zijbalk:** klik navigeert; actief item gemarkeerd. In Blade = `request()->routeIs(...)` (al aanwezig).
- **Actieknoppen** (Bijwerken, Updates starten, Back-up nu, Meten, Activeren, 2FA instellen):
  in het prototype togglen ze naar een "gedaan/gestart"-staat (groen). In de echte app koppelen aan de
  bestaande POST-routes/forms (updates.store, customers.updates, sites.pagespeed, licenses.activate, …)
  met de huidige `data-confirm`-bevestiging; toon daarna "in wachtrij/aangevraagd" zoals nu.
- **Licht/donker:** `theme.js` zet `data-theme` op `<html>` + localStorage (al aanwezig) — hergebruiken.
- **Activiteit-tabs:** wisselen via querystring `?tab=` (zoals nu), geen client-state nodig.
- **Hover:** knop donkerder + schaduw; kaart licht omhoog (`translateY(-3px)`, `--shadow-lg`).
- **Motion:** rustig, `--ease-out`, 140–240ms. Respecteer `prefers-reduced-motion`.

## State Management
Server-rendered Blade; geen SPA-state nodig. Kleuren/tellingen komen uit de bestaande controllers.
Thema in localStorage. Actie-feedback komt van de bestaande job/queue-statussen (`partials/job-result`).

## Assets
- Rood pijl-merkicoon: `panel/public/img/nextbuzz-arrow.png` (al in repo; in dit pakket meegeleverd als
  `nextbuzz-arrow.png`). Overige iconen = Lucide-stijl lijniconen.

## Files in dit pakket (ontwerpreferentie)
- `OC Monitor - Werkplek.dc.html` — **het complete prototype**: alle 12 pagina's, zijbalk, licht/donker.
- `OC Monitor.dc.html` — het optieboard: getrouwe recreatie van het huidige Overzicht (0a) +
  varianten 2a (zijbalk/takenlijst, gekozen) en 2b (bovenbalk/kaarten) ter vergelijking.
- `nextbuzz-arrow.png` — merkicoon.

Open de .dc.html-bestanden in een browser om de bedoelde look & behavior te zien. Herbouw ze in
`panel/resources/views/*` met de bestaande Blade-layout en de CSS-tokens hierboven.

## Screenshots (`screenshots/`)
Referentiebeelden van het prototype (richting "Werkplek"). Licht, plus twee in donker thema.
- `overzicht-licht.png`, `overzicht-donker.png`
- `sites-licht.png`
- `klanten-licht.png`
- `updates-licht.png`, `updates-donker.png`
- `pagespeed-licht.png`
- `kwetsbaarheden-licht.png`
- `versies-licht.png`
- `licenties-licht.png`
- `wp-gebruikers-licht.png`
- `rapporten-licht.png`
- `activiteit-licht.png`
- `instellingen-licht.png`
