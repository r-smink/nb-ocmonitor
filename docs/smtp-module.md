# SMTP-module (client-plugin 0.15.0)

Het panel zet de module aan met de schakelaar `smtp_module` (`tweaks.set`, `TweakCatalog::SWITCH_MIN_VERSION`).
Daarna toont de clientpagina (menu **NB OC Monitor**) de tabbladen **SMTP**, **E-maillog** en **Meldingen**.
De SMTP-gegevens worden alleen op de site ingevuld. Het panel ziet nooit een wachtwoord.

## Onderdelen en eigenaarschap

| Map / bestand | Inhoud |
|---|---|
| `src/Smtp/Module.php` | Aan/uit, `state()`, `summary()` (inventaris), testmail- en check-handlers. Roept in vaste volgorde aan: `Log\LogModule`, `Backup\BackupModule`, `Alerts\AlertsModule` (`register(bool $active)`). |
| `src/Smtp/{Options,Crypto,Mailer,Diagnose,Preflight,Conflicts,TestMail}.php` | De primaire verbinding, versleuteling, phpmailer_init, foutuitleg, de verbindingscheck en de testmail. |
| `src/Smtp/Log/*` + `LogTab` | Interceptor van `wp_mail`, de tabel `nb_ocm_mail_log` en de tab E-maillog. |
| `src/Smtp/Backup/*` + `BackupSection` | De backupverbinding (eigen optie `nb_ocm_smtp_backup`) en de kaart onderaan de tab SMTP. |
| `src/Smtp/Alerts/*` + `AlertsTab` | Meldingen (e-mail, webhook, panel-events, adminmelding) en de tab Meldingen. |
| `src/Admin/Ui.php`, `assets/admin.css` | Gedeelde bouwstenen en stijl. Nieuwe CSS komt per onderdeel in een eigen blok onderaan `admin.css`. |

## Contract tussen de onderdelen

### 1. De Log-interceptor bezit de verzendstroom
- **Begin:** `wp_mail` (filter, prioriteit `PHP_INT_MAX`). De interceptor bewaart de originele argumenten en maakt een log-rij aan met status `pending`.
- **Tijdens het versturen:** `phpmailer_init` (prioriteit `PHP_INT_MAX`). Legt de definitieve afzender, headers en Message-ID vast.
- **Gelukt:** `wp_mail_succeeded` → status `sent`.
- **Mislukt:** `wp_mail_failed` → eerst de backup proberen (punt 2), dan de definitieve status vastleggen.
- **Altijd daarna:** `do_action('nb_ocm_smtp_mail_result', array $result)` (punt 3).
- **Herkenbare headers:**
  - `X-NB-OCM-Test: 1` (`TestMail::HEADER`) markeert een testmail: wel loggen met `is_test = true`, geen backup.
  - `X-NB-OCM-Alert: 1` markeert een meldingsmail van de module zelf. Die wordt gelogd, maar krijgt **geen** backup en **geen** `nb_ocm_smtp_mail_result`, zodat er geen lussen ontstaan.
- **Geneste aanroepen:** zolang de interceptor de backup laat proberen (`Log\Interceptor::isNested()`), negeert hij zijn eigen hooks. De backup-poging is dus geen nieuwe log-rij.
- **Ook als de module niet actief is:** staat de module aan, dan logt hij, ook als `state()['active']` false is (niet ingesteld, conflict). `connection` is dan `wordpress`.

### 2. Backup
```php
$backup = apply_filters('nb_ocm_smtp_try_backup', null, array $wpMailArgs, string $primaryError);
// null = geen backup ingesteld/actief, of testmail
// ['ok' => bool, 'error' => string]  (error leeg als ok)
```
- Implementeert de Backup-module. Die draait alleen als `$active` true is en er een complete backupverbinding is.
- De backup verstuurt opnieuw met `wp_mail(...$wpMailArgs)`, met de extra header `X-NB-OCM-Connection: backup`.
- Tijdens die aanroep zet een eigen `phpmailer_init` op `PHP_INT_MAX` de backupinstellingen op PHPMailer, via `new Mailer($backupOptions, $pass)` en `configure()`. Daarna worden de hooks weer verwijderd.

### 3. Resultaat (voor meldingen en panel)
```php
do_action('nb_ocm_smtp_mail_result', [
    'log_id' => ?int,
    'status' => 'sent' | 'failed',        // definitief, na een eventuele backup
    'connection' => 'primary' | 'backup' | 'wordpress',
    'to' => list<string>,
    'subject' => string,
    'error' => string,                    // fout van de primaire verbinding ('' als gelukt)
    'backup_error' => string,             // '' als er geen backup was of die lukte
    'diagnose_code' => string,            // Diagnose::explain()['code'], '' als gelukt
    'initiator' => string,                // plugin/thema/WP Core die de mail verstuurde
    'is_test' => bool,
    'time' => int,
]);
```
- `status = sent` met `connection = backup` betekent: de primaire verbinding faalde en de backup heeft de mail verstuurd.

### 4. Events naar het panel (via `EventRecorder::record`, met throttling door de Alerts-module)
| Type | Wanneer | result |
|---|---|---|
| `smtp.failed` | mail definitief niet verstuurd | failed |
| `smtp.backup_used` | primair mislukt, backup verstuurd | success |
| `smtp.recovered` | eerste geslaagde mail na een of meer mislukte | success |

- `object` is de ontvanger (de eerste).
- `data` bevat `{subject, error, diagnose_code, initiator, log_id, suppressed}`.
- `ocm.smtp_changed` en `ocm.smtp_test` komen uit `Module`.

## Afmelden, opruimen
- Elke module ruimt zijn eigen cron-hook op in `Cron::unschedule()` en zijn eigen opties in `uninstall.php`.
