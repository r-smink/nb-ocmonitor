<?php

namespace App\Ocm;

use App\Models\Setting;
use App\Models\Site;
use App\Models\Vulnerability;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;

/**
 * Wordfence Intelligence Vulnerability Data Feed (v3). Gratis, ook zakelijk, met een API-sleutel
 * van wordfence.com en met bronvermelding. De feed is groot; hij wordt naar schijf gedownload en
 * als stroom gelezen. Alleen wat op onze sites voorkomt wordt bewaard.
 */
final class Vulnerabilities
{
    public const SETTING = 'vulnerability_feed';
    public const ATTRIBUTION = 'Gegevens over kwetsbaarheden: Wordfence Intelligence (wordfence.com). Bevat informatie uit de CVE-database van MITRE.';

    public static function enabled(): bool
    {
        return (string) config('ocm.vulnerabilities.api_key') !== '';
    }

    /** @return array{records: int, matched: int} */
    public static function sync(): array
    {
        $started = now()->subSecond();
        $path = tempnam(sys_get_temp_dir(), 'wfi');
        try {
            $response = Http::withToken((string) config('ocm.vulnerabilities.api_key'))->timeout(600)->sink($path)
                ->get((string) config('ocm.vulnerabilities.endpoint'));
            if (!$response->successful()) {
                throw new \RuntimeException('Wordfence-feed gaf HTTP ' . $response->status());
            }

            $wanted = self::componentKeys(Site::query()->whereNotNull('public_key')->get());
            $records = 0;
            $matched = 0;
            foreach (Items::fromFile($path, ['decoder' => new ExtJsonDecoder(true)]) as $uuid => $record) {
                $records++;
                if (!is_array($record)) {
                    continue;
                }
                foreach ((array) ($record['software'] ?? []) as $software) {
                    $type = (string) ($software['type'] ?? '');
                    $slug = strtolower((string) ($software['slug'] ?? ''));
                    if (!isset($wanted[$type . ':' . $slug])) {
                        continue;
                    }
                    Vulnerability::query()->updateOrCreate(['uuid' => (string) ($record['id'] ?? $uuid), 'type' => $type, 'slug' => $slug], [
                        'title' => mb_substr((string) ($record['title'] ?? ''), 0, 500) ?: null,
                        'affected' => array_values(array_filter((array) ($software['affected_versions'] ?? []), 'is_array')),
                        'patched_versions' => array_values(array_filter((array) ($software['patched_versions'] ?? []), 'is_string')),
                        'cvss_score' => is_numeric($record['cvss']['score'] ?? null) ? (float) $record['cvss']['score'] : null,
                        'cvss_rating' => is_string($record['cvss']['rating'] ?? null) ? mb_substr($record['cvss']['rating'], 0, 20) : null,
                        'cve' => is_string($record['cve'] ?? null) ? mb_substr($record['cve'], 0, 40) : null,
                        'informational' => !empty($record['informational']),
                        'remediation' => is_string($software['remediation'] ?? null) ? mb_substr($software['remediation'], 0, 500) : null,
                        'published_at' => is_string($record['published'] ?? null) ? \Carbon\Carbon::parse($record['published']) : null,
                    ])->touch();
                    $matched++;
                }
            }
            if ($records === 0) {
                throw new \RuntimeException('Lege feed ontvangen');
            }
            // Wat niet meer in de feed staat (of niet meer op onze sites voorkomt) weg.
            Vulnerability::query()->where('updated_at', '<', $started)->delete();
            Setting::put(self::SETTING, ['synced_at' => now()->toIso8601String(), 'records' => $records, 'matched' => $matched, 'error' => null]);

            return ['records' => $records, 'matched' => $matched];
        } catch (\Throwable $e) {
            Setting::put(self::SETTING, array_merge(Setting::get(self::SETTING), ['error' => mb_substr($e->getMessage(), 0, 300), 'failed_at' => now()->toIso8601String()]));
            throw $e;
        } finally {
            @unlink($path);
        }
    }

    /**
     * Componenten van de sites als "type:slug" (plugin:elementor, theme:astra, core:wordpress).
     *
     * @param Collection<int, Site> $sites
     * @return array<string, true>
     */
    public static function componentKeys(Collection $sites): array
    {
        $keys = ['core:wordpress' => true];
        foreach ($sites as $site) {
            foreach (self::components($site) as $c) {
                $keys[$c['type'] . ':' . $c['slug']] = true;
            }
        }

        return $keys;
    }

    /** @return list<array{type: string, slug: string, name: string, version: string}> */
    public static function components(Site $site): array
    {
        $inv = $site->inventory ?? [];
        $out = [];
        if (!empty($inv['core']['version'])) {
            $out[] = ['type' => 'core', 'slug' => 'wordpress', 'name' => 'WordPress', 'version' => (string) $inv['core']['version']];
        }
        foreach ((array) ($inv['plugins'] ?? []) as $p) {
            $file = (string) ($p['file'] ?? '');
            $slug = strtolower((string) ($p['slug'] ?? (dirname($file) !== '.' ? dirname($file) : basename($file, '.php'))));
            if ($slug !== '' && !empty($p['version'])) {
                $out[] = ['type' => 'plugin', 'slug' => $slug, 'name' => (string) ($p['name'] ?? $slug), 'version' => (string) $p['version']];
            }
        }
        foreach ((array) ($inv['themes'] ?? []) as $t) {
            if (!empty($t['stylesheet']) && !empty($t['version'])) {
                $out[] = ['type' => 'theme', 'slug' => strtolower((string) $t['stylesheet']), 'name' => (string) ($t['name'] ?? $t['stylesheet']), 'version' => (string) $t['version']];
            }
        }

        return $out;
    }

    /**
     * Kwetsbaarheden van de geïnstalleerde versies op één site.
     *
     * @param Collection<string, Collection<int, Vulnerability>>|null $index "type:slug" => records (voorgeladen)
     * @return list<array{component: array{type: string, slug: string, name: string, version: string}, vulnerability: Vulnerability, fixed: ?string}>
     */
    public static function forSite(Site $site, ?Collection $index = null): array
    {
        $components = self::components($site);
        $index ??= self::index(collect($components)->map(fn ($c) => $c['type'] . ':' . $c['slug'])->all());
        $out = [];
        foreach ($components as $c) {
            foreach ($index->get($c['type'] . ':' . $c['slug'], collect()) as $v) {
                if ($v->affects($c['version'])) {
                    $out[] = ['component' => $c, 'vulnerability' => $v, 'fixed' => $v->fixedIn($c['version'])];
                }
            }
        }

        return $out;
    }

    /**
     * @param string[] $keys "type:slug"
     * @return Collection<string, Collection<int, Vulnerability>>
     */
    public static function index(array $keys): Collection
    {
        if ($keys === []) {
            return collect();
        }
        $slugs = array_values(array_unique(array_map(fn ($k) => explode(':', $k, 2)[1] ?? '', $keys)));

        return Vulnerability::query()->whereIn('slug', $slugs)->get()->groupBy(fn (Vulnerability $v) => $v->type . ':' . $v->slug);
    }
}
