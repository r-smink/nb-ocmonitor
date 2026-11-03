# NB OC Monitor: protocolspecificatie v1

Dit document is het contract tussen het panel en de client-plugin. De referentie-implementatie staat in [`protocol/src`](../protocol/src). De testvectoren in [`protocol/tests/vectors.json`](../protocol/tests/vectors.json) zijn bindend: elke implementatie moet exact dezelfde waarden opleveren.

## 1. Uitgangspunten

- **Ed25519** (libsodium `crypto_sign_detached`) voor alle handtekeningen. Binnen WordPress zonder ext-sodium valt de plugin terug op het meegeleverde `sodium_compat`.
- **Geen gedeelde langlevende geheimen.** Het panel kent alleen de publieke sleutel van elke site, en elke site alleen de publieke sleutel(s) van het panel.
- **Domain separation.** Elk berichttype heeft een eigen contextlabel als eerste regel van de ondertekende string. Een handtekening voor het ene type is nooit geldig voor een ander type.
- **De ontvanger bepaalt de context.** Host, endpoint-pad en site-id komen bij verificatie uit de eigen configuratie van de ontvanger, nooit uit het verzoek (geen `Host`-header).
- **Fail closed, zwijgend.** Elke afwijzing levert extern een generieke `404` op. De reden (`VerificationFailed::reason()`) gaat alleen naar het lokale log.
- Alle verkeer gaat via HTTPS. De plugin weigert te koppelen zonder HTTPS, tenzij `NB_OCM_ALLOW_HTTP` gezet is (alleen lokaal).

## 2. Koppelen (pairing)

1. Het panel maakt een site aan en genereert een **koppelcode**, 15 minuten geldig:
   `nbocm1.` + base64url(JSON) met de velden
   `v` (1), `panel_url` (https), `site_id`, `panel_kid`, `panel_pk` (base64url, 32 bytes), `secret` (base64url, 32 random bytes), `exp` (unix-tijd).
   De parser controleert ook of `panel_kid` hoort bij `panel_pk`.
2. De plugin genereert een eigen sleutelpaar en stuurt naar `POST {panel_url}/api/pair` een **bewijs**:
   ```
   canonical = "NBOCM-v1-pair\n{site_id}\n{site_pk_b64}\n{site_url}\n{ts}"
   hmac      = base64url(HMAC-SHA256(canonical, secret))   // bewijst kennis van de code
   sig       = base64url(Ed25519(canonical, site_sk))      // bewijst bezit van de private key
   ```
3. Het panel controleert het site-id, `|now - ts| ≤ 300 s`, de HMAC (constante tijd) en de handtekening. Daarna slaat het `site_pk` op en **maakt het het secret direct ongeldig** (eenmalig gebruik, ook als de verificatie mislukt na een geldige HMAC).

## 3. Ondertekende strings

Velden worden gescheiden door `\n`, zonder afsluitende newline. Elk veld wordt eerst gevalideerd, zodat geen enkel veld een newline kan bevatten:

| Veld | Regel |
|---|---|
| `method` | `GET` of `POST` |
| `host` | lowercase; `:443`/`:80` weggelaten; `[a-z0-9.-]{1,253}(:poort)?` |
| `path` | logisch endpoint, bv. `/nb-ocm/v1/cmd` (de REST-route, los van de permalink-structuur); `^/[A-Za-z0-9/_.-]{1,200}$` |
| `site_id`, `key_id` | `[A-Za-z0-9_-]{1,64}` |
| `timestamp` | unix-seconden, decimaal zonder voorloopnullen |
| `nonce` | base64url-tekens, 22–64 lang (de referentie-implementatie gebruikt 24 random bytes, dus 32 tekens) |
| `body_sha256` | sha256 van de ruwe body, hex lowercase |

**Verzoek** (panel→site: `NBOCM-v1-cmd`; site→panel: `NBOCM-v1-site`):
```
{context}\n{METHOD}\n{host}\n{path}\n{site_id}\n{key_id}\n{timestamp}\n{nonce}\n{body_sha256}
```

**Antwoord** van de site op een command:
```
NBOCM-v1-resp\n{site_id}\n{key_id}\n{timestamp}\n{request_nonce}\n{status}\n{body_sha256}
```

**Login-token**: `NBOCM-v1-login\n{key_id}\n{payload_b64}`

**Key-id** = `"k_"` + de eerste 16 hex-tekens van `sha256(public_key)`. Dit wordt altijd afgeleid en nooit uit configuratie vertrouwd.

## 4. Verzoeken

Headers (hoofdletterongevoelig; `_` en `-` gelijkwaardig, omdat WordPress `_` gebruikt):

| Header | Inhoud |
|---|---|
| `X-NBOCM-Site` | site-id |
| `X-NBOCM-Key` | key-id van de ondertekenaar |
| `X-NBOCM-Ts` | timestamp |
| `X-NBOCM-Nonce` | nonce |
| `X-NBOCM-Sig` | base64url(Ed25519-handtekening), 64 bytes |

Body van een command (JSON): `{"command": "...", "args": {...}, "job_id": "...", "expires_at": <unix>}`. De plugin weigert ook commands waarvan `expires_at` verstreken is. `job_id` maakt de uitvoering idempotent.

**Verificatievolgorde** (`Verifier::verifyRequest`):
1. `X-NBOCM-Site` is gelijk aan het eigen site-id (`hash_equals`).
2. De timestamp is syntactisch geldig en valt binnen het venster `|now - ts| ≤ 90 s`.
3. De key-id is bekend. De resolver levert de publieke sleutel, en de afgeleide key-id van die sleutel moet overeenkomen.
4. De canonical string wordt opgebouwd met de **eigen** host, het eigen pad en het eigen site-id.
5. De handtekening wordt gecontroleerd (strikte base64url, exact 64 bytes).
6. Pas daarna wordt de nonce verbruikt (atomair, bewaard tot `ts + venster + 1`). Een al gebruikte nonce betekent `replay`.
7. Daarna, in de plugin: het JSON-schema van de body, `expires_at`, het command in de allowlist en binnen de scopes van de site.

   Scopes: `read`, `events` en `updates` (commands `update.plugin`, `update.theme`, `update.core`, `update.translations`) staan sinds plugin 0.3.0 altijd aan. Bij 0.2.0 was `updates` optioneel. Update-commands accepteren alleen namen van geïnstalleerde componenten, nooit een pakket-URL.

Antwoorden ondertekent de site met haar eigen sleutel (`Signer::signResponse`). Het panel verifieert ze met `Verifier::verifyResponse` en accepteert **geen** ongesigneerd antwoord. Het antwoord is gebonden aan de nonce van het verzoek, dus het panel hoeft daar zelf geen nonce voor op te slaan.

## 5. Site → panel (events, heartbeat)

Hetzelfde verzoekformaat met context `NBOCM-v1-site`, ondertekend met de site-sleutel. Path is het panel-endpoint, bijvoorbeeld `/api/sites/{site_id}/events`. Host is de host van `panel_url`. Het panel gebruikt een nonce-store in Redis (`SET NX EX`).

## 6. 1-click login-token

Formaat: `{key_id}.{base64url(JSON claims)}.{base64url(sig)}`, maximaal 2048 tekens.

Claims: `aud` = `"login"`, `site_id`, `host`, `wp_user`, `panel_user`, `iat`, `exp` (≤ 120 s na `iat`; standaard 60 s), `nonce`.

De browser biedt het token aan via een auto-submit-**POST** naar `wp-login.php?action=nb_ocm_login`. Het token staat nooit in een URL. De plugin controleert achtereenvolgens:
1. formaat en lengte
2. key-id en handtekening (vóór het parsen van de JSON)
3. het claimschema (types en patronen)
4. `aud`
5. site-id en host
6. levensduur en `iat` niet in de toekomst (speling 30 s)
7. `exp` (speling 30 s)
8. de nonce eenmalig verbruiken

Daarna komt de controle in de plugin zelf: scope `admin_login`, de gebruiker bestaat en heeft de verwachte rol.

## 7. Sleutelrotatie

- Panel: maak een nieuw sleutelpaar en stuur met de **oude** sleutel een `keys.rotate`-command dat de nieuwe publieke sleutel toevoegt. De plugin accepteert dan beide sleutels (`StaticKeyResolver` met meerdere sleutels). Zodra alle sites bevestigd hebben, verwijdert een tweede command de oude sleutel.
- Noodgeval (private key van het panel gelekt): de oude sleutel kan niet meer vertrouwd worden om de rotatie te ondertekenen. Dan is het herstel: `NB_OCM_DISABLED` via de hosting (Plesk) en opnieuw koppelen. Zie `docs/threat-model.md`.
- Site: een `keys.rotate_site`-command laat de plugin een nieuw paar maken. Het antwoord wordt nog met de oude sleutel ondertekend en bevat de nieuwe publieke sleutel.

## 8. Testvectoren

Seeds: panel `0x01`×32, site `0x02`×32, pairing-secret `0x03`×32, timestamp `1767225600`, host `klant.example.nl`, site-id `site_01`. Zie `protocol/tests/vectors.json`. Genereer de vectoren alleen opnieuw (`tests/generate-vectors.php`) bij een bewuste protocolwijziging. Dat is dan ook een versieverhoging (`v2`-contextlabels).

## 9. Versionering

De versie zit in de contextlabels (`NBOCM-v1-*`) en het prefix van de koppelcode (`nbocm1.`). Een v2 krijgt nieuwe labels. Tijdens een overgang kunnen ontvangers beide versies accepteren, en v1 wordt daarna uitgeschakeld.
