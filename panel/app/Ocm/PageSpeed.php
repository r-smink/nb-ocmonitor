<?php

namespace App\Ocm;

use App\Models\PagespeedResult;
use App\Models\Site;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Google PageSpeed Insights API v5 (plan §4.3). Meet mobiel en desktop tegelijk en slaat de
 * Lighthouse-scores, labmetingen, CrUX-velddata en de grootste besparingen op.
 */
final class PageSpeed
{
    public const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

    private const CATEGORIES = ['performance', 'accessibility', 'best-practices', 'seo'];

    /**
     * @return list<PagespeedResult>
     */
    public static function measure(Site $site): array
    {
        $timeout = (int) config('ocm.pagespeed.timeout_seconds', 90);
        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (string $strategy) => $pool->as($strategy)->timeout($timeout)->connectTimeout(10)->acceptJson()->get(self::url($site->url, $strategy)),
            array_keys(PagespeedResult::STRATEGIES)
        ));

        $results = [];
        foreach (array_keys(PagespeedResult::STRATEGIES) as $strategy) {
            $response = $responses[$strategy] ?? null;
            try {
                $attributes = self::parse($response);
            } catch (\RuntimeException $e) {
                $attributes = ['error' => mb_substr($e->getMessage(), 0, 500)];
            }
            $results[] = $site->pagespeedResults()->create($attributes + ['strategy' => $strategy, 'measured_at' => now()]);
        }

        return $results;
    }

    /** De API wil category meerdere keren als losse parameter, dus niet via http_build_query met een array. */
    public static function url(string $siteUrl, string $strategy): string
    {
        $query = http_build_query(array_filter([
            'url' => rtrim($siteUrl, '/') . '/',
            'strategy' => $strategy,
            'locale' => 'nl',
            'key' => config('ocm.pagespeed.api_key') ?: null,
        ]));
        foreach (self::CATEGORIES as $category) {
            $query .= '&category=' . $category;
        }

        return self::ENDPOINT . '?' . $query;
    }

    /**
     * @return array<string, mixed>
     */
    public static function parse(mixed $response): array
    {
        if (!$response instanceof Response) {
            throw new \RuntimeException('Geen verbinding met PageSpeed Insights' . ($response instanceof \Throwable ? ': ' . $response->getMessage() : ''));
        }
        $json = $response->json();
        if (!$response->successful() || !is_array($json)) {
            $message = is_array($json) ? (string) ($json['error']['message'] ?? '') : '';
            throw new \RuntimeException('PageSpeed Insights gaf HTTP ' . $response->status() . ($message !== '' ? ': ' . $message : ''));
        }
        $lighthouse = $json['lighthouseResult'] ?? null;
        if (!is_array($lighthouse)) {
            throw new \RuntimeException('Geen Lighthouse-resultaat in het antwoord');
        }

        $score = fn (string $key) => is_numeric($lighthouse['categories'][$key]['score'] ?? null) ? (int) round($lighthouse['categories'][$key]['score'] * 100) : null;
        $audit = fn (string $key) => is_numeric($lighthouse['audits'][$key]['numericValue'] ?? null) ? (float) $lighthouse['audits'][$key]['numericValue'] : null;
        $ms = fn (string $key) => ($v = $audit($key)) !== null ? (int) round($v) : null;

        return [
            'performance' => $score('performance'),
            'accessibility' => $score('accessibility'),
            'best_practices' => $score('best-practices'),
            'seo' => $score('seo'),
            'lcp_ms' => $ms('largest-contentful-paint'),
            'fcp_ms' => $ms('first-contentful-paint'),
            'tbt_ms' => $ms('total-blocking-time'),
            'ttfb_ms' => $ms('server-response-time'),
            'cls' => ($cls = $audit('cumulative-layout-shift')) !== null ? round($cls, 3) : null,
            'field' => self::field($json['loadingExperience'] ?? null),
            'opportunities' => self::opportunities((array) ($lighthouse['audits'] ?? [])),
            'error' => null,
        ];
    }

    /**
     * Velddata van echte Chrome-bezoekers (CrUX); null als Google te weinig bezoekers heeft.
     *
     * @return array<string, mixed>|null
     */
    private static function field(mixed $experience): ?array
    {
        if (!is_array($experience) || empty($experience['metrics']) || !is_array($experience['metrics'])) {
            return null;
        }
        $metric = fn (string $key) => is_numeric($experience['metrics'][$key]['percentile'] ?? null) ? (int) $experience['metrics'][$key]['percentile'] : null;
        $cls = $metric('CUMULATIVE_LAYOUT_SHIFT_SCORE');

        return [
            'category' => is_string($experience['overall_category'] ?? null) ? $experience['overall_category'] : null,
            'lcp_ms' => $metric('LARGEST_CONTENTFUL_PAINT_MS'),
            'inp_ms' => $metric('INTERACTION_TO_NEXT_PAINT'),
            'cls' => $cls !== null ? $cls / 100 : null,
            'fcp_ms' => $metric('FIRST_CONTENTFUL_PAINT_MS'),
            'ttfb_ms' => $metric('EXPERIMENTAL_TIME_TO_FIRST_BYTE'),
            'origin_fallback' => !empty($experience['origin_fallback']),
        ];
    }

    /**
     * De zes grootste besparingen ("opportunities").
     *
     * @param array<string, mixed> $audits
     * @return list<array{id: string, title: string, savings_ms: int, display: ?string}>
     */
    private static function opportunities(array $audits): array
    {
        $list = [];
        foreach ($audits as $id => $audit) {
            if (!is_array($audit) || ($audit['details']['type'] ?? null) !== 'opportunity' || (float) ($audit['score'] ?? 1) >= 0.9) {
                continue;
            }
            $savings = (int) round((float) ($audit['details']['overallSavingsMs'] ?? 0));
            if ($savings < 100) {
                continue;
            }
            $list[] = [
                'id' => mb_substr((string) $id, 0, 80),
                'title' => mb_substr((string) ($audit['title'] ?? $id), 0, 200),
                'savings_ms' => $savings,
                'display' => is_string($audit['displayValue'] ?? null) ? mb_substr($audit['displayValue'], 0, 120) : null,
            ];
        }
        usort($list, fn ($a, $b) => $b['savings_ms'] <=> $a['savings_ms']);

        return array_slice($list, 0, 6);
    }
}
