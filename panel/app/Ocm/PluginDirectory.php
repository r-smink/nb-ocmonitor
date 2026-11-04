<?php

namespace App\Ocm;

use App\Models\PluginDirectoryEntry;
use App\Models\Site;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Openbare gegevens van wordpress.org over de geïnstalleerde plugins (ocm:plugin-directory,
 * dagelijks): is de plugin gesloten (vaak om beveiligingsredenen) of al jaren niet bijgewerkt?
 * Alleen slugs gaan naar api.wordpress.org; geen gegevens over de sites zelf.
 */
final class PluginDirectory
{
    public const API = 'https://api.wordpress.org/plugins/info/1.2/';

    /** Zo lang is een opgehaalde waarde geldig. */
    private const RECHECK_DAYS = 7;

    /** Niet bijgewerkt sinds zo veel jaar = mogelijk verlaten. */
    public const ABANDONED_YEARS = 2;

    /** @return array{checked: int, closed: int} */
    public static function refresh(int $limit = 300): array
    {
        $slugs = [];
        foreach (Site::query()->whereNotNull('inventory')->get(['id', 'inventory']) as $site) {
            foreach ((array) (($site->inventory ?? [])['plugins'] ?? []) as $p) {
                $slug = (string) ($p['slug'] ?? '');
                if (preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $slug) && $slug !== 'nb-ocmonitor-client') {
                    $slugs[$slug] = true;
                }
            }
        }
        $fresh = PluginDirectoryEntry::query()->where('checked_at', '>=', now()->subDays(self::RECHECK_DAYS))->pluck('slug')->all();
        $todo = array_slice(array_values(array_diff(array_keys($slugs), $fresh)), 0, $limit);

        $checked = 0;
        $closed = 0;
        foreach (array_chunk($todo, 20) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(fn (string $slug) => $pool->as($slug)->timeout(20)->acceptJson()
                ->withHeaders(['User-Agent' => 'NB-OC-Monitor/1.0'])
                ->get(self::API, ['action' => 'plugin_information', 'request' => ['slug' => $slug, 'fields' => ['sections' => false, 'versions' => false, 'reviews' => false, 'banners' => false, 'icons' => false]]]), $chunk));
            foreach ($chunk as $slug) {
                $response = $responses[$slug] ?? null;
                if (!$response instanceof Response) {
                    continue; // netwerkfout: volgende keer opnieuw
                }
                $entry = self::parse($slug, $response->status(), (array) ($response->json() ?? []));
                if ($entry === null) {
                    continue;
                }
                PluginDirectoryEntry::query()->updateOrCreate(['slug' => $slug], $entry + ['checked_at' => now()]);
                $checked++;
                $closed += $entry['closed'] ? 1 : 0;
            }
        }

        return ['checked' => $checked, 'closed' => $closed];
    }

    /**
     * @param array<string, mixed> $json
     * @return array<string, mixed>|null null = tijdelijke fout, niets opslaan
     */
    public static function parse(string $slug, int $status, array $json): ?array
    {
        if (($json['error'] ?? null) === 'closed') {
            return [
                'listed' => true, 'closed' => true,
                'closed_reason' => isset($json['reason_text']) ? mb_substr((string) $json['reason_text'], 0, 120) : (isset($json['reason']) ? mb_substr((string) $json['reason'], 0, 120) : null),
                'closed_date' => self::date($json['closed_date'] ?? null)?->toDateString(),
                'last_updated' => null,
            ];
        }
        if ($status === 404 || isset($json['error'])) {
            // Niet op wordpress.org (premium of eigen plugin): niets te zeggen.
            return ['listed' => false, 'closed' => false, 'closed_reason' => null, 'closed_date' => null, 'last_updated' => null];
        }
        if ($status !== 200 || ($json['slug'] ?? null) !== $slug) {
            return null;
        }

        return ['listed' => true, 'closed' => false, 'closed_reason' => null, 'closed_date' => null, 'last_updated' => self::date($json['last_updated'] ?? null)];
    }

    /** @return array<string, PluginDirectoryEntry> */
    public static function all(): array
    {
        try {
            return PluginDirectoryEntry::query()->where('listed', true)->get()->keyBy('slug')->all();
        } catch (\Throwable) {
            return []; // tabel bestaat nog niet
        }
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            // wordpress.org: "2024-05-01 3:04pm GMT"
            return CarbonImmutable::parse(str_replace(' GMT', '', $value), 'UTC');
        } catch (\Throwable) {
            return null;
        }
    }
}
