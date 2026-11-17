<?php

namespace App\Ocm\Alerts;

use App\Models\Setting;
use App\Models\Site;
use App\Ocm\SiteIssues;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Stuurt SMTP-events van de sites (smtp.failed, smtp.backup_used, smtp.recovered) direct door naar
 * ServerDash (ons interne monitoringscherm). Eén URL voor alle sites, in te stellen onder
 * Instellingen → Meldingen. Het token zit in de URL, dus de hele URL staat versleuteld in de
 * database en wordt nooit volledig getoond. Alleen https naar publieke adressen, zonder redirects.
 *
 * Er draait geen queue-worker (alleen web, signer en scheduler). Daarom: bij een push van de site
 * (web) versturen we na het antwoord (terminating-callback, nginx/fpm sluit de verbinding eerst),
 * bij een pull via de signer synchroon met een korte time-out. Fouten worden altijd opgevangen.
 */
final class ServerDash
{
    public const SETTING = 'serverdash';

    /** Laatste verzending en laatste fout, apart van de configuratie (wordt vanuit signer en web geschreven). */
    public const STATUS = 'serverdash_status';

    public const TYPES = ['smtp.failed', 'smtp.backup_used', 'smtp.recovered'];

    /** Oudere events (achterstand na een storing) niet meer doorsturen. */
    public const MAX_AGE_MINUTES = 120;

    /** Hooguit zoveel verzendingen per ontvangen batch events. */
    private const MAX_PER_BATCH = 10;

    /** @return array{url: ?string, enabled: bool} url nog versleuteld */
    public static function config(): array
    {
        $stored = Setting::get(self::SETTING);

        return ['url' => is_string($stored['url'] ?? null) && $stored['url'] !== '' ? $stored['url'] : null, 'enabled' => !empty($stored['enabled'])];
    }

    /** Ontsleutelde URL als doorsturen aan staat, anders null. */
    public static function activeUrl(): ?string
    {
        $config = self::config();

        return $config['enabled'] ? self::decrypt($config['url']) : null;
    }

    public static function configuredUrl(): ?string
    {
        return self::decrypt(self::config()['url']);
    }

    public static function enabled(): bool
    {
        return self::activeUrl() !== null;
    }

    /** Ingekorte weergave voor het scherm: host + "…" + laatste 4 tekens. */
    public static function masked(?string $url = null): string
    {
        $url ??= self::configuredUrl();
        if ($url === null || $url === '') {
            return '';
        }

        return (string) parse_url($url, PHP_URL_HOST) . '…' . substr($url, -4);
    }

    /**
     * @param string|null $url null = huidige URL houden, '' = verwijderen (zet doorsturen uit)
     */
    public static function save(?string $url, bool $enabled): void
    {
        $current = self::config();
        $encrypted = match (true) {
            $url === null => $current['url'],
            $url === '' => null,
            default => Crypt::encryptString($url),
        };
        Setting::put(self::SETTING, ['url' => $encrypted, 'enabled' => $encrypted !== null && $enabled]);
    }

    /** Foutmelding als de URL niet mag, anders null. */
    public static function urlProblem(string $url): ?string
    {
        if ($problem = Channels::urlProblem($url, 'serverdash')) {
            return $problem;
        }
        if (!str_contains((string) parse_url($url, PHP_URL_PATH), '/hooks/mail/')) {
            return 'Gebruik de URL uit ServerDash → Beheer → E-mailmeldingen (https://…/hooks/mail/…).';
        }

        return null;
    }

    /** @return array{last_sent_at: ?string, last_error: ?string, last_error_at: ?string} */
    public static function status(): array
    {
        $stored = Setting::get(self::STATUS);

        return ['last_sent_at' => $stored['last_sent_at'] ?? null, 'last_error' => $stored['last_error'] ?? null, 'last_error_at' => $stored['last_error_at'] ?? null];
    }

    /**
     * Nieuw opgeslagen events (rijen uit EventIngestor). Pakt alleen de SMTP-types binnen
     * MAX_AGE_MINUTES. Op het web na het antwoord, anders (signer, tests) meteen.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function forwardNew(Site $site, array $rows): void
    {
        $rows = array_values(array_filter($rows, fn (array $row) => self::wanted($row)));
        if ($rows === [] || !self::enabled()) {
            return;
        }
        $rows = array_slice($rows, 0, self::MAX_PER_BATCH);
        $send = static function () use ($site, $rows): void {
            foreach ($rows as $row) {
                if (!self::deliver(self::payload($site, $row))) {
                    break; // ServerDash onbereikbaar: niet de hele batch op time-outs laten wachten
                }
            }
        };
        if (app()->runningInConsole()) {
            $send();
        } else {
            app()->terminating($send);
        }
    }

    /** @param array<string, mixed> $row */
    public static function wanted(array $row): bool
    {
        if (!in_array($row['type'] ?? '', self::TYPES, true)) {
            return false;
        }
        $at = Carbon::parse((string) $row['occurred_at'], 'UTC');

        return $at->gte(now()->subMinutes(self::MAX_AGE_MINUTES));
    }

    /**
     * Verstuurt één payload naar de ingestelde URL. Gooit nooit; legt succes of fout vast.
     *
     * @param array<string, mixed> $payload
     */
    public static function deliver(array $payload, ?string $url = null): bool
    {
        try {
            $url ??= self::activeUrl();
            if ($url === null) {
                throw new \RuntimeException('Geen ServerDash-URL ingesteld');
            }
            self::send($url, $payload);
            self::record(null);

            return true;
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 200);
            try {
                self::record($error);
                Log::warning('ServerDash: doorsturen mislukt', ['event' => $payload['event'] ?? null, 'error' => $error]);
            } catch (\Throwable) {
                // ook het vastleggen mag de verwerking van events niet breken
            }

            return false;
        }
    }

    /** @param array<string, mixed> $payload */
    public static function send(string $url, array $payload): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (Channels::$httpGuard && !Channels::isPublicHost($host)) {
            throw new \RuntimeException('Adres van ServerDash wijst naar een intern netwerk');
        }
        $json = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $response = Http::withHeaders(['Content-Type' => 'application/json', 'User-Agent' => 'NB-OC-Monitor/1.0'])
            ->timeout(5)->connectTimeout(5)->withoutRedirecting()->withBody($json, 'application/json')->post($url);
        if (!$response->successful()) {
            throw new \RuntimeException('HTTP ' . $response->status());
        }
    }

    /**
     * Payload volgens het contract met ServerDash (POST /hooks/mail/<token>).
     *
     * @param array<string, mixed> $row rij uit site_events (data als JSON-string of array)
     * @return array<string, mixed>
     */
    public static function payload(Site $site, array $row): array
    {
        $data = $row['data'] ?? null;
        $data = is_string($data) ? (json_decode($data, true) ?: []) : (is_array($data) ? $data : []);
        $type = (string) $row['type'];
        $code = (string) ($data['diagnose_code'] ?? '');
        $logId = is_numeric($data['log_id'] ?? null) ? (int) $data['log_id'] : null;
        $object = trim((string) ($row['object'] ?? ''));
        $error = (string) ($data['error'] ?? '');
        $title = SiteIssues::smtpDiagnoseTitle($code);
        $message = self::message($type, (string) $site->name, $title !== '' ? $title : \Illuminate\Support\Str::limit(trim($error), 120));
        $maillog = SiteIssues::smtpLogUrl($site) . ($logId !== null ? '&log=' . $logId : '');

        return [
            'event' => $type,
            'source' => 'oc-panel',
            'site_title' => (string) $site->name,
            'site_url' => (string) $site->url,
            'message' => $message,
            'text' => $message,
            'mail' => [
                'to' => $object !== '' ? [$object] : [],
                'subject' => (string) ($data['subject'] ?? ''),
                'error' => $error,
                'backup_error' => (string) ($data['backup_error'] ?? ''),
                'diagnose_code' => $code,
                'diagnose_title' => $title,
                'initiator' => (string) ($data['initiator'] ?? ''),
                'log_id' => $logId,
                'time' => Carbon::parse((string) $row['occurred_at'], 'UTC')->toIso8601String(),
            ],
            'suppressed' => (int) ($data['suppressed'] ?? 0),
            'panel_url' => route('sites.show', $site),
            'maillog_url' => $maillog,
        ];
    }

    /** Voorbeeld voor de knop Testmelding versturen. @return array<string, mixed> */
    public static function testPayload(): array
    {
        $message = 'Testmelding van OC Monitor: als je dit ziet, komen e-mailmeldingen van de sites aan in ServerDash.';
        $url = rtrim((string) config('app.url'), '/');

        return [
            'event' => 'smtp.test',
            'source' => 'oc-panel',
            'site_title' => 'Testsite',
            'site_url' => $url,
            'message' => $message,
            'text' => $message,
            'mail' => [
                'to' => ['test@example.nl'],
                'subject' => 'Testmelding',
                'error' => 'SMTP Error: Could not authenticate.',
                'backup_error' => '',
                'diagnose_code' => 'auth',
                'diagnose_title' => SiteIssues::smtpDiagnoseTitle('auth'),
                'initiator' => 'OC Monitor',
                'log_id' => null,
                'time' => now()->toIso8601String(),
            ],
            'suppressed' => 0,
            'panel_url' => route('dashboard'),
            'maillog_url' => $url . '/wp-admin/admin.php?page=nb-ocmonitor&tab=maillog',
        ];
    }

    private static function message(string $type, string $site, string $reason): string
    {
        return match ($type) {
            'smtp.backup_used' => "E-mail van {$site} via de backupverbinding verstuurd: de primaire SMTP-verbinding faalde" . ($reason !== '' ? " ({$reason})." : '.'),
            'smtp.recovered' => "E-mail van {$site} wordt weer verstuurd.",
            default => "E-mail van {$site} niet verstuurd" . ($reason !== '' ? ": {$reason}." : '.'),
        };
    }

    private static function record(?string $error): void
    {
        $status = Setting::get(self::STATUS);
        if ($error === null) {
            $status['last_sent_at'] = now()->toIso8601String();
        } else {
            $status['last_error'] = $error;
            $status['last_error_at'] = now()->toIso8601String();
        }
        Setting::put(self::STATUS, $status);
    }

    private static function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return Crypt::decryptString($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
