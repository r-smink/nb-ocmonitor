<?php

namespace App\Ocm;

use App\Models\Setting;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Google Analytics 4 en Search Console, alleen lezen. Eén keer koppelen met het Next Buzz-account
 * dat toegang heeft tot de properties van de klanten (OAuth-client van het type "Intern").
 * De refresh-token staat versleuteld in de database.
 */
final class GoogleApi
{
    public const SETTING = 'google_oauth';
    public const SCOPES = [
        'https://www.googleapis.com/auth/analytics.readonly',
        'https://www.googleapis.com/auth/webmasters.readonly',
        'https://www.googleapis.com/auth/userinfo.email',
    ];

    public static function configured(): bool
    {
        return (string) config('ocm.google.client_id') !== '' && (string) config('ocm.google.client_secret') !== '';
    }

    public static function connected(): bool
    {
        return !empty(Setting::get(self::SETTING)['refresh_token']);
    }

    /** @return array<string, mixed> */
    public static function status(): array
    {
        $s = Setting::get(self::SETTING);

        return ['connected' => !empty($s['refresh_token']), 'email' => $s['email'] ?? null, 'connected_at' => $s['connected_at'] ?? null, 'error' => $s['error'] ?? null];
    }

    public static function redirectUri(): string
    {
        return route('analytics.callback');
    }

    public static function authUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => config('ocm.google.client_id'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
        ]);
    }

    public static function connect(string $code): string
    {
        $r = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'code' => $code, 'client_id' => config('ocm.google.client_id'), 'client_secret' => config('ocm.google.client_secret'),
            'redirect_uri' => self::redirectUri(), 'grant_type' => 'authorization_code',
        ]);
        if (!$r->successful() || !$r->json('refresh_token')) {
            throw new \RuntimeException('Google gaf geen toegang: ' . ($r->json('error_description') ?? $r->json('error') ?? 'HTTP ' . $r->status()));
        }
        $email = Http::withToken((string) $r->json('access_token'))->timeout(15)->get('https://www.googleapis.com/oauth2/v2/userinfo')->json('email');
        Setting::put(self::SETTING, ['refresh_token' => Crypt::encryptString((string) $r->json('refresh_token')), 'email' => $email, 'connected_at' => now()->toIso8601String(), 'error' => null]);
        Cache::put('ocm.google.token', (string) $r->json('access_token'), now()->addSeconds(max(60, (int) $r->json('expires_in', 3600) - 120)));
        self::forgetLists();

        return (string) $email;
    }

    public static function disconnect(): void
    {
        $s = Setting::get(self::SETTING);
        if (!empty($s['refresh_token'])) {
            try {
                Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => Crypt::decryptString($s['refresh_token'])]);
            } catch (\Throwable) {
                // intrekken mislukt: de token wordt hier toch verwijderd
            }
        }
        Setting::put(self::SETTING, []);
        Cache::forget('ocm.google.token');
        self::forgetLists();
    }

    private static function token(): string
    {
        return Cache::remember('ocm.google.token', 3000, function () {
            $s = Setting::get(self::SETTING);
            if (empty($s['refresh_token'])) {
                throw new \RuntimeException('Google is niet gekoppeld');
            }
            $r = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('ocm.google.client_id'), 'client_secret' => config('ocm.google.client_secret'),
                'refresh_token' => Crypt::decryptString($s['refresh_token']), 'grant_type' => 'refresh_token',
            ]);
            if (!$r->successful()) {
                Setting::put(self::SETTING, array_merge($s, ['error' => 'Vernieuwen van de toegang mislukt (' . ($r->json('error') ?? $r->status()) . '); koppel Google opnieuw.']));
                throw new \RuntimeException('Google-toegang verlopen; koppel opnieuw onder Instellingen');
            }

            return (string) $r->json('access_token');
        });
    }

    private static function forgetLists(): void
    {
        Cache::forget('ocm.google.ga4');
        Cache::forget('ocm.google.gsc');
    }

    /** @return array<string, string> "properties/123" => "Account › Property" */
    public static function ga4Properties(): array
    {
        return Cache::remember('ocm.google.ga4', 3600, function () {
            $out = [];
            $page = null;
            do {
                $r = Http::withToken(self::token())->timeout(20)->get('https://analyticsadmin.googleapis.com/v1beta/accountSummaries', array_filter(['pageSize' => 200, 'pageToken' => $page]));
                if (!$r->successful()) {
                    throw new \RuntimeException('Analytics Admin API gaf HTTP ' . $r->status() . ': ' . (string) $r->json('error.message', ''));
                }
                foreach ((array) $r->json('accountSummaries', []) as $account) {
                    foreach ((array) ($account['propertySummaries'] ?? []) as $p) {
                        $out[(string) $p['property']] = ($account['displayName'] ?? '') . ' › ' . ($p['displayName'] ?? $p['property']);
                    }
                }
                $page = $r->json('nextPageToken');
            } while ($page);
            asort($out);

            return $out;
        });
    }

    /** @return string[] Search Console-properties, bijv. "sc-domain:klant.nl" of "https://www.klant.nl/" */
    public static function gscSites(): array
    {
        return Cache::remember('ocm.google.gsc', 3600, function () {
            $r = Http::withToken(self::token())->timeout(20)->get('https://www.googleapis.com/webmasters/v3/sites');
            if (!$r->successful()) {
                throw new \RuntimeException('Search Console API gaf HTTP ' . $r->status() . ': ' . (string) $r->json('error.message', ''));
            }
            $sites = array_values(array_filter(array_map(fn ($s) => $s['siteUrl'] ?? null, (array) $r->json('siteEntry', []))));
            sort($sites);

            return $sites;
        });
    }

    /** Beste Search Console-property voor een site: eerst het domein, dan de exacte URL. */
    public static function suggestGsc(Site $site, array $available): ?string
    {
        $host = strtolower((string) parse_url($site->url, PHP_URL_HOST));
        $bare = preg_replace('/^www\./', '', $host);
        foreach (['sc-domain:' . $bare, rtrim($site->url, '/') . '/', 'https://' . $host . '/', 'https://www.' . $bare . '/'] as $candidate) {
            if (in_array($candidate, $available, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Cijfers voor een periode. Afgesloten periodes worden bewaard, de lopende periode 6 uur.
     *
     * @return array<string, mixed>|null
     */
    public static function report(Site $site, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        if (!self::connected() || ($site->ga4_property === null && $site->gsc_property === null)) {
            return null;
        }
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $row = DB::table('analytics_snapshots')->where('site_id', $site->id)->where('period_from', $from->toDateString())->where('period_to', $to->toDateString())->first();
        $fresh = $row && ($to->lt(CarbonImmutable::today()->subDays(3)) || CarbonImmutable::parse($row->fetched_at)->gt(now()->subHours(6)));
        if ($fresh) {
            return json_decode($row->data, true);
        }
        $data = ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'ga4' => null, 'gsc' => null, 'errors' => []];
        if ($site->ga4_property) {
            try {
                $data['ga4'] = self::ga4($site->ga4_property, $from, $to);
            } catch (\Throwable $e) {
                $data['errors'][] = 'Analytics: ' . $e->getMessage();
            }
        }
        if ($site->gsc_property) {
            try {
                $data['gsc'] = self::gsc($site->gsc_property, $from, $to);
            } catch (\Throwable $e) {
                $data['errors'][] = 'Search Console: ' . $e->getMessage();
            }
        }
        if ($data['ga4'] !== null || $data['gsc'] !== null) {
            DB::table('analytics_snapshots')->upsert([[
                'site_id' => $site->id, 'period_from' => $from->toDateString(), 'period_to' => $to->toDateString(),
                'data' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'fetched_at' => now(),
            ]], ['site_id', 'period_from', 'period_to'], ['data', 'fetched_at']);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private static function ga4(string $property, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (preg_match('#^properties/\d+$#', $property) !== 1) {
            throw new \RuntimeException('Ongeldige property');
        }
        $range = [['startDate' => $from->toDateString(), 'endDate' => $to->toDateString()]];
        $run = function (array $body) use ($property) {
            $r = Http::withToken(self::token())->timeout(30)->post('https://analyticsdata.googleapis.com/v1beta/' . $property . ':runReport', $body);
            if (!$r->successful()) {
                throw new \RuntimeException('HTTP ' . $r->status() . ': ' . (string) $r->json('error.message', ''));
            }

            return $r->json();
        };
        $metrics = ['activeUsers', 'sessions', 'screenPageViews', 'engagementRate', 'averageSessionDuration'];
        $totals = $run(['dateRanges' => $range, 'metrics' => array_map(fn ($m) => ['name' => $m], $metrics)]);
        $values = array_map(fn ($v) => (float) ($v['value'] ?? 0), (array) ($totals['rows'][0]['metricValues'] ?? []));
        $pages = $run(['dateRanges' => $range, 'dimensions' => [['name' => 'pagePath']], 'metrics' => [['name' => 'screenPageViews']],
            'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]], 'limit' => 10]);
        $daily = $run(['dateRanges' => $range, 'dimensions' => [['name' => 'date']], 'metrics' => [['name' => 'sessions']], 'orderBys' => [['dimension' => ['dimensionName' => 'date']]]]);
        $sources = $run(['dateRanges' => $range, 'dimensions' => [['name' => 'sessionDefaultChannelGroup']], 'metrics' => [['name' => 'sessions']],
            'orderBys' => [['metric' => ['metricName' => 'sessions'], 'desc' => true]], 'limit' => 6]);

        return [
            'users' => (int) ($values[0] ?? 0),
            'sessions' => (int) ($values[1] ?? 0),
            'pageviews' => (int) ($values[2] ?? 0),
            'engagement' => round(($values[3] ?? 0) * 100, 1),
            'duration' => (int) round($values[4] ?? 0),
            'pages' => array_map(fn ($r) => ['path' => (string) $r['dimensionValues'][0]['value'], 'views' => (int) $r['metricValues'][0]['value']], (array) ($pages['rows'] ?? [])),
            'daily' => array_map(fn ($r) => (int) $r['metricValues'][0]['value'], (array) ($daily['rows'] ?? [])),
            'sources' => array_map(fn ($r) => ['name' => (string) $r['dimensionValues'][0]['value'], 'sessions' => (int) $r['metricValues'][0]['value']], (array) ($sources['rows'] ?? [])),
        ];
    }

    /** @return array<string, mixed> */
    private static function gsc(string $siteUrl, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $query = function (array $body) use ($siteUrl) {
            $r = Http::withToken(self::token())->timeout(30)->post('https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($siteUrl) . '/searchAnalytics/query', $body);
            if (!$r->successful()) {
                throw new \RuntimeException('HTTP ' . $r->status() . ': ' . (string) $r->json('error.message', ''));
            }

            return (array) $r->json('rows', []);
        };
        $base = ['startDate' => $from->toDateString(), 'endDate' => $to->toDateString()];
        $total = $query($base)[0] ?? [];
        $queries = $query($base + ['dimensions' => ['query'], 'rowLimit' => 10]);

        return [
            'clicks' => (int) ($total['clicks'] ?? 0),
            'impressions' => (int) ($total['impressions'] ?? 0),
            'ctr' => round((float) ($total['ctr'] ?? 0) * 100, 1),
            'position' => round((float) ($total['position'] ?? 0), 1),
            'queries' => array_map(fn ($r) => ['query' => (string) ($r['keys'][0] ?? ''), 'clicks' => (int) ($r['clicks'] ?? 0), 'impressions' => (int) ($r['impressions'] ?? 0), 'position' => round((float) ($r['position'] ?? 0), 1)], $queries),
        ];
    }
}
