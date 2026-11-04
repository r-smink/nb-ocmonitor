# Prompt voor Claude Code — site-detailpagina + in-page icoonbalk

Plak dit in Claude Code vanuit de root van de **r-smink/nb-ocmonitor** repo (panel/, Laravel + Blade).
Lees eerst `design_handoff_ocmonitor_ui/README.md` en open `OC Monitor - Werkplek.dc.html` in een
browser als visuele referentie (navigeer naar Sites → "Openen" op een site).

---

## Opdracht

Bouw een **site-detailpagina** (`sites/show`) in de bestaande Werkplek-stijl, met links — direct
**naast het hoofd-zijmenu** — een **smalle verticale icoonbalk** die naar de secties op de pagina
scrollt. Gebruik de bestaande Blade-layout (`layouts/app.blade.php`), de CSS-tokens uit
`public/css/serverdash.css` / `public/css/ocm.css` / `public/css/ds/tokens/*`, en de bestaande
patronen (`.panel`, `.btn`, `.chip`, `.stat`). Kopieer geen HTML uit het prototype; herbouw het.

### Layout
Drie kolommen naast elkaar: **(1)** het bestaande hoofd-zijmenu, **(2)** de nieuwe smalle icoonbalk
(~60px, sticky, `top: 84px`, eigen card met border + shadow), **(3)** de scrollbare detailcontent
(max 1240px). De icoonbalk hoort visueel bij de content, niet bij het hoofdmenu.

### Secties (elk een kaart met een anker-id)
In deze volgorde, elk met `id="sd-<key>"` en `scroll-margin-top: 84px` (zodat de sticky header de
titel niet afdekt):
1. **status** — statuskop (sitenaam, klant, WP/PHP-versie, statusbadge, "Site openen") +
   4 metric-kaarten (Beschikbaarheid, SSL-certificaat, Laatste back-up, Responstijd), gekleurde
   bovenrand per status (groen/oranje/rood).
2. **updates** — openstaande updates van deze site, per regel "van → naar" + knop "Bijwerken",
   plus "Alles bijwerken" in de kop. Beveiligingsbadge waar van toepassing.
3. **backups** — lijst back-ups (tijd, type, grootte) met "Terugzetten"; "Nu back-up maken" in de kop.
4. **beveiliging** — kwetsbaarheden van deze site (CVSS-score-badge, titel, CVE, fix-versie, knop);
   bij geen lekken een groene "Geen bekende kwetsbaarheden"-regel.
5. **pagespeed** — ronde mobiel/desktop-scores (groen ≥90, oranje ≥50, rood <50) + LCP/CLS/TBT.
6. **gebruikers** — WordPress-gebruikers van de site (login, rol, 2FA-badge, laatst ingelogd).
7. **activiteit** — recente activiteit van de site (tijd + omschrijving + status-stip).

### De icoonbalk (het kernstuk)
- Eén icoon per sectie (Lucide, outline): status=`layout-grid`, updates=`refresh-cw`,
  backups=`clock`, beveiliging=`shield`, pagespeed=`gauge`, gebruikers=`users`, activiteit=`activity`.
- Elk icoon is een anchor `href="#sd-<key>"`. Klik = **smooth scroll** naar de sectie
  (`html { scroll-behavior: smooth }`), geen `scrollIntoView` nodig.
- **Badges:** klein getal rechtsboven op het icoon voor openstaande updates en kwetsbaarheden
  (alleen tonen als > 0; kwetsbaarheden-badge rood, updates oranje).
- **Actief icoon:** rood gevuld (`--red-500`, witte glyph). De actieve sectie wordt bepaald door
  **scrollspy** met een `IntersectionObserver` (rootMargin ~`-88px 0px -55% 0px`): de sectie die het
  meest in beeld is, markeert zijn icoon. Klikken zet het actieve icoon ook meteen.
- **Hover-label:** bij hover verschijnt het sectielabel als een **zwevende pill-tooltip óver de
  content**, rechts náást het icoon — de balk zelf wordt dus **niet** breder en de icoonpositie
  verschuift niet. Dit is een descendant-hover (`.rail-item:hover .rail-label`) in een echte
  CSS-regel; de pill is `position: absolute; left: calc(100% + 10px)`, card-achtergrond + border +
  shadow, met een korte fade/translate-in. Geef de balk een hogere `z-index` dan de content zodat
  de pill erboven ligt.

### Gedrag / koppeling in de echte app
- Elke sectie leest zijn data uit de bestaande controllers/models voor die site; de `show`-route
  geeft het site-model + relaties mee. Routeer vanaf de Sites-kaart "Openen" → `sites.show`.
- Actieknoppen (Bijwerken, Alles bijwerken, Nu back-up maken, Terugzetten, Opnieuw meten) koppelen
  aan de bestaande POST-routes/jobs met de huidige `data-confirm`-bevestiging; toon daarna de
  "in wachtrij/aangevraagd"-status zoals elders in het panel.
- Werkt in licht én donker (bestaande `theme.js` / `data-theme`). Respecteer `prefers-reduced-motion`
  (dan geen smooth scroll en geen label-transition).
- Responsive: onder ~900px mag de icoonbalk een horizontale strip boven de content worden.

### Belangrijk
- Houd je strikt aan de design-tokens (rood `#E22830` spaarzaam, warme grijzen, pill-knoppen,
  `--radius-lg` kaarten, zachte schaduwen, Schibsted/Hanken Grotesk).
- Icoon-only balk met tooltip = de "smal" variant; geen tekst permanent in de balk.
