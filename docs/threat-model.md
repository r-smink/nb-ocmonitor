# NB OC Monitor: dreigingsmodel

## Wat we beschermen

1. **Beheertoegang tot alle klantsites.** Dit is het kroonjuweel. Wie het panel of het protocol misbruikt, kan op elke gekoppelde site code installeren of admins aanmaken.
2. **Backups van klantsites.** Die bevatten volledige databases, met persoonsgegevens.
3. **Activity logs.** Persoonsgegevens van medewerkers van klanten (AVG, Next Buzz is verwerker).
4. **Integriteit van rapportages.** Klanten moeten kunnen vertrouwen op wat we rapporteren.

## Ontwerpdoel

> Eén gecompromitteerd onderdeel mag niet automatisch leiden tot volledige, onopgemerkte toegang tot alle klantsites.

## Vertrouwensgrenzen

```
[Medewerker-browser] --SSO+WebAuthn--> [Panel web]  --DB/Redis-->  [Signer]  --Ed25519/HTTPS-->  [Client-plugin op klantsite]
                                                                                                     |
[Object storage] <--------------- pre-signed upload-URL's (alleen schrijven, per object) ------------+
```

- **Panel web**: heeft geen signing key. Het kan alleen jobs in de DB zetten.
- **Signer**: de enige plek met de private key van het panel. Voert policies uit (allowlist, rate limits, WebAuthn voor gevoelige commands) en schrijft het hash-chained audit log.
- **Client-plugin**: vertrouwt alleen de publieke sleutel(s) van het panel en voert alleen commands uit de allowlist uit, binnen de scopes van de site.

## Scenario's

| # | Scenario | Gevolg zonder maatregel | Maatregel | Restrisico |
|---|---|---|---|---|
| T1 | Iemand op internet roept het plugin-endpoint aan | Remote takeover | Elk verzoek Ed25519-ondertekend; generieke 404; endpoint niet in REST-index | Bugs in de plugin zelf. Daarom review, statische analyse en een pentest |
| T2 | Replay van een onderschept verzoek | Herhaalde actie | Venster van 90 s, eenmalige nonce (atomair), `job_id`-idempotentie, `expires_at` | Geen |
| T3 | Verzoek voor site A wordt naar site B gestuurd | Actie op verkeerde site | Site-id en host in de handtekening, en de ontvanger gebruikt eigen waarden | Geen |
| T4 | MITM of DNS-kaping tussen panel en site | Valse inventaris, verborgen updates | HTTPS en ondertekende antwoorden met de site-sleutel | Een MITM kan verkeer blokkeren (dat wordt gedetecteerd via de heartbeat) |
| T5 | Panel-DB of DB-backup lekt | Sleutels gelekt | Geen private keys in de DB; gevoelige velden versleuteld met APP_KEY (buiten de DB) | Metadata (sitelijst, versies) lekt |
| T6 | Web-container van het panel gehackt (bijv. via een Filament- of Laravel-lek) | Willekeurige commands naar alle sites | Signer is een apart proces met policies: allowlist (geen code-executie), rate limits, WebAuthn voor `user.create`-admin, `scope.change` en login; alarm bij een afwijkend patroon | Updates of installs van plugins **uit wordpress.org of de eigen repo** blijven mogelijk binnen de rate limits. Mitigatie: een install vanuit de eigen repo vereist een sha256 in het command en die repo is apart beveiligd |
| T7 | Signer-host volledig gehackt (private key gelekt) | Commands naar alle sites binnen de allowlist | Allowlist in de plugin (geen arbitrary PHP, geen file-upload), scopes per site, release-signing van de plugin met een **aparte offline sleutel** (zodat een aanvaller de plugin niet kan vervangen) | Ernstig: een aanvaller kan een kwaadaardige plugin van wordpress.org installeren. Herstel: runbook "sleutel gelekt" (kill-switch via Plesk, opnieuw koppelen) |
| T8 | Account van een medewerker gekaapt | Misbruik namens die medewerker | Entra SSO met MFA, WebAuthn-step-up, korte sessies, toegang tot het panel alleen via VPN of Cloudflare Access, audit log | Insider met geldige token en WebAuthn-sleutel |
| T9 | Kwaadwillende admin op een klantsite | Plugin uitschakelen of manipuleren, eigen events vervalsen | Heartbeat-detectie, gaten in volgnummers, inventaris-diff vanuit het panel. De site-sleutel bewijst alleen dat iets van die site komt, niet dat het waar is | Events van een gecompromitteerde site zijn niet betrouwbaar. Zo'n site wordt als "onbetrouwbaar" gemarkeerd |
| T10 | Gecompromitteerde site valt het panel aan | Via event-endpoint of antwoorden het panel aanvallen (XSS, injection) | Strikte validatie van alles wat een site stuurt; escaping in Filament; maximale omvang van antwoorden; een site kan alleen data voor het eigen site-id aanleveren | Geen bekende |
| T11 | Gecompromitteerde site leest of wist backups van andere sites | Datalek, backups weg | Pre-signed URL's per object, alleen schrijven; object lock | Geen |
| T12 | Supply chain: kwaadaardige update van onze eigen plugin | Alle sites tegelijk | Releases ondertekend met een offline Ed25519-sleutel, verificatie in de plugin vóór installatie; CI met `composer audit`; reviews | Een gekaapte ontwikkelaarslaptop vóór het signen. Daarom reviews met twee personen op releases |
| T13 | Login-token lekt (logs, Referer, browsergeschiedenis) | Sessie-overname | Token alleen via POST, eenmalig, 60 s geldig, gebonden aan site en host | Malware in de browser van de medewerker |
| T14 | Koppelcode lekt | Aanvaller koppelt eigen "site" of kaapt de koppeling | Code 15 min geldig en eenmalig; het panel toont na koppelen de URL en de key-id ter bevestiging | Een aanvaller die de code binnen 15 min gebruikt. Mitigatie: het panel controleert of `site_url` overeenkomt met de aangemaakte site |
| T15 | Brute force of DoS op het plugin-endpoint | Load op de klantsite | Goedkope controles eerst (site-id, timestamp, formaat) vóór de crypto; nonce pas na de handtekening | Volumetrische DoS. Die is niet anders dan bij elke andere URL |

## Runbook "panel-sleutel gelekt" (samengevat)

1. Stop de signer en blokkeer uitgaand verkeer van de panel-host.
2. Zet via Plesk (bulk, script) `define('NB_OCM_DISABLED', true);` in `wp-config.php` van alle gekoppelde sites. Dit vereist geen vertrouwen in de gelekte sleutel.
3. Onderzoek het audit log en de site-events van de periode van compromittering, en daarnaast de serverlogs (ServerDash).
4. Maak een nieuw panel-sleutelpaar en koppel alle sites opnieuw (`wp nb-ocm pair` via WP-CLI in bulk).
5. Melden: beoordeel de meldplicht datalekken (AP, binnen 72 uur) en informeer klanten.

## Bewust niet ondersteund (verkleint het aanvalsoppervlak)

- Willekeurige PHP of SQL uitvoeren, een bestandseditor, of bestanden uploaden of verwijderen via de plugin.
- Plugin-installatie vanaf een willekeurige URL.
- Automatisch malware opschonen via de plugin.
