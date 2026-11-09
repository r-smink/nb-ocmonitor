<?php

namespace App\Ocm;

use App\Models\PagespeedResult;
use App\Models\Site;
use App\Models\SiteMetric;
use Carbon\CarbonImmutable;

/**
 * Trends per site: één meetpunt per dag uit de inventaris (database, bestanden, plugins), plus de
 * PageSpeed-metingen die er al zijn. Een nieuwe inventaris op dezelfde dag overschrijft het punt.
 */
final class SiteMetrics
{
    public const DAYS = 180;

    public static function record(Site $site): void
    {
        $inv = $site->inventory ?? [];
        $storage = is_array($inv['storage'] ?? null) ? $inv['storage'] : [];
        $plugins = collect((array) ($inv['plugins'] ?? []))->filter(fn ($p) => is_array($p));
        $int = fn (mixed $v) => is_int($v) && $v >= 0 ? $v : null;

        SiteMetric::query()->updateOrCreate(['site_id' => $site->id, 'day' => now()->timezone('Europe/Amsterdam')->toDateString()], [
            'db_bytes' => $int(($inv['cleanup'] ?? [])['db_bytes'] ?? null),
            'content_bytes' => $int($storage['content_bytes'] ?? null),
            'uploads_bytes' => $int($storage['uploads_bytes'] ?? null),
            'disk_free_bytes' => $int($storage['disk_free_bytes'] ?? null),
            'storage_partial' => !empty($storage['partial']),
            'plugins' => min(65535, $plugins->count()),
            'plugins_active' => min(65535, $plugins->where('active', true)->count()),
            'themes' => min(65535, count((array) ($inv['themes'] ?? []))),
        ]);
    }

    /**
     * Reeksen voor de grafieken: per reeks een lijst [datum, waarde], oudste eerst.
     *
     * @return array<string, list<array{0: CarbonImmutable, 1: int|float}>>
     */
    public static function series(Site $site, int $days = self::DAYS): array
    {
        $since = now()->subDays($days);
        $metrics = SiteMetric::query()->where('site_id', $site->id)->where('day', '>=', $since->toDateString())->orderBy('day')->get();
        $pick = fn (string $field) => $metrics->filter(fn (SiteMetric $m) => $m->{$field} !== null)
            ->map(fn (SiteMetric $m) => [CarbonImmutable::parse($m->day), $m->{$field}])->values()->all();
        $psi = fn (string $strategy) => $site->pagespeedResults()->where('strategy', $strategy)->whereNull('error')->whereNotNull('performance')
            ->where('measured_at', '>=', $since)->orderBy('measured_at')->get(['measured_at', 'performance'])
            ->map(fn (PagespeedResult $r) => [CarbonImmutable::parse($r->measured_at), (int) $r->performance])->values()->all();

        return [
            'db' => $pick('db_bytes'),
            'content' => $pick('content_bytes'),
            'plugins' => $pick('plugins'),
            'psi_mobile' => $psi('mobile'),
            'psi_desktop' => $psi('desktop'),
        ];
    }

    /** "245 MB", "1,2 GB" */
    public static function bytes(int|float|null $bytes): string
    {
        if ($bytes === null) {
            return '–';
        }
        foreach (['GB' => 1024 ** 3, 'MB' => 1024 ** 2, 'kB' => 1024] as $unit => $size) {
            if (abs($bytes) >= $size) {
                $value = $bytes / $size;

                return number_format($value, $value < 10 ? 1 : 0, ',', '.') . ' ' . $unit;
            }
        }

        return (int) $bytes . ' B';
    }
}
