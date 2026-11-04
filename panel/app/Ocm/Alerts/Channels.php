<?php

namespace App\Ocm\Alerts;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Gedeelde meldingskanalen (Instellingen → Meldingen): Slack, Microsoft Teams en een eigen webhook.
 * URL's en het webhookgeheim staan versleuteld in de database. Alleen https naar publieke adressen,
 * zonder redirects, zodat een kanaal geen interne diensten kan bereiken.
 */
final class Channels
{
    public const SETTING = 'alert_channels';

    public const TYPES = [
        'slack' => 'Slack (incoming webhook)',
        'teams' => 'Microsoft Teams (workflow-webhook)',
        'webhook' => 'Eigen webhook (JSON, HMAC-ondertekend)',
    ];

    /** @return array<string, array<string, mixed>> type => config (url/secret nog versleuteld) */
    public static function all(): array
    {
        $stored = Setting::get(self::SETTING);

        return array_intersect_key($stored, self::TYPES);
    }

    /** @return array<string, array{filter: AlertFilter, url: string, secret: ?string}> alleen kanalen met een URL */
    public static function active(): array
    {
        $out = [];
        foreach (self::all() as $type => $config) {
            $url = self::decrypt($config['url'] ?? null);
            if ($url !== null) {
                $out[$type] = ['filter' => AlertFilter::from($config['filter'] ?? ['enabled' => true]), 'url' => $url, 'secret' => self::decrypt($config['secret'] ?? null)];
            }
        }

        return $out;
    }

    public static function hasUrl(string $type): bool
    {
        return !empty(self::all()[$type]['url']);
    }

    /**
     * @param array<string, mixed> $filter
     */
    public static function save(string $type, ?string $url, bool $clear, array $filter): void
    {
        $all = Setting::get(self::SETTING);
        $current = $all[$type] ?? [];
        if ($clear) {
            unset($all[$type]);
        } else {
            $entry = ['filter' => AlertFilter::normalize($filter + ['enabled' => true])];
            $entry['filter']['quiet_from'] = $entry['filter']['quiet_to'] = null; // kanalen hebben geen stille uren
            $entry['url'] = $url !== null && $url !== '' ? Crypt::encryptString($url) : ($current['url'] ?? null);
            if ($type === 'webhook') {
                $entry['secret'] = $current['secret'] ?? Crypt::encryptString(Str::random(40));
            }
            $all[$type] = $entry;
        }
        Setting::put(self::SETTING, $all);
    }

    public static function webhookSecret(): ?string
    {
        return self::decrypt(self::all()['webhook']['secret'] ?? null);
    }

    /** Foutmelding als de URL niet mag, anders null. */
    public static function urlProblem(string $url, string $type): ?string
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return 'Gebruik een https-adres zonder gebruikersnaam of wachtwoord.';
        }
        $host = strtolower($parts['host']);
        if ($type === 'slack' && $host !== 'hooks.slack.com') {
            return 'Een Slack-webhook begint met https://hooks.slack.com/.';
        }
        if (!self::isPublicHost($host)) {
            return 'Dit adres wijst naar een intern of gereserveerd netwerk.';
        }

        return null;
    }

    public static function isPublicHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
            $ips = array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records));
        }
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verstuurt één bericht met alle items. Gooit een exception bij een fout.
     *
     * @param list<array<string, mixed>> $items zie Alerts::item()
     */
    public static function send(string $type, string $url, ?string $secret, array $items): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (self::$httpGuard && !self::isPublicHost($host)) {
            throw new \RuntimeException('Adres van het kanaal wijst naar een intern netwerk');
        }
        $body = match ($type) {
            'slack' => ['text' => Message::title($items), 'blocks' => [['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => Message::slack($items)]]]],
            'teams' => ['type' => 'message', 'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'content' => Message::teamsCard($items)]]],
            default => ['event' => 'ocm.alerts', 'sent_at' => now()->toIso8601String(), 'items' => $items],
        };
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $headers = ['Content-Type' => 'application/json', 'User-Agent' => 'NB-OC-Monitor/1.0'];
        if ($type === 'webhook' && $secret !== null) {
            $ts = (string) time();
            $headers['X-OCM-Timestamp'] = $ts;
            $headers['X-OCM-Signature'] = 'sha256=' . hash_hmac('sha256', $ts . '.' . $json, $secret);
        }
        $response = Http::withHeaders($headers)->timeout(15)->withoutRedirecting()->withBody($json, 'application/json')->post($url);
        if (!$response->successful()) {
            throw new \RuntimeException('HTTP ' . $response->status());
        }
    }

    /** Uit in tests (Http::fake met nep-hosts). */
    public static bool $httpGuard = true;

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
