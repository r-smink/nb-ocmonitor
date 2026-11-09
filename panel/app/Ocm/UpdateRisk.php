<?php

namespace App\Ocm;

use App\Models\PluginChangelog;
use App\Models\PluginDirectoryEntry;
use App\Models\Site;
use App\Models\UpdateInsight;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Hoe groot de kans is dat een update iets breekt: laag, middel of hoog, met de redenen erbij.
 * Op basis van de versiesprong, hoe lang het onderdeel op de site niet is bijgewerkt, de eisen van
 * de nieuwe versie (wordpress.org), trefwoorden in de changelog en, als dat aan staat, de
 * inschatting van Claude. Het label is een hulpmiddel; het blokkeert niets.
 */
final class UpdateRisk
{
    public const RANK = ['low' => 0, 'medium' => 1, 'high' => 2];

    public const LABELS = ['low' => 'laag risico', 'medium' => 'middel risico', 'high' => 'hoog risico'];

    /** Zo lang niet bijgewerkt op de site = grote sprong. */
    private const STALE_MONTHS = 12;

    /** Zoveel versies in één keer telt als grote sprong. */
    private const MANY_VERSIONS = 10;

    private const HIGH_WORDS = '/\bbreaking\b|backwards?[- ]incompatib|not backwards?[- ]compatible|\bincompatib/i';
    private const MEDIUM_WORDS = '/database (update|upgrade|migration)|\bmigrat(e|ion)\b|(drop(s|ped)?|remov(e|ed|es)) support|minimum (php|wordpress)|requires? (php|wordpress) \d|\bdeprecat/i';

    /**
     * Risico van alle openstaande updates van deze sites.
     *
     * @param Collection<int, Site> $sites
     * @return array<int, array<string, array{level: string, reasons: list<array{level: string, text: string}>, summary: ?string}>> site-id => "type:id" => risico
     */
    public static function forSites(Collection $sites): array
    {
        $updates = $sites->mapWithKeys(fn (Site $s) => [$s->id => $s->availableUpdates()]);
        $slugs = $updates->flatten(1)->where('type', 'plugin')->pluck('slug')->map(fn ($s) => strtolower((string) $s))->unique()->values()->all();
        $context = [
            'directory' => PluginDirectory::all(),
            'changelogs' => $slugs === [] ? collect() : PluginChangelog::query()->whereIn('slug', $slugs)->get()->keyBy('slug'),
            'insights' => $slugs === [] ? collect() : UpdateInsight::query()->where('type', 'plugin')->whereIn('slug', $slugs)->get()
                ->keyBy(fn (UpdateInsight $i) => UpdateInsight::key('plugin', $i->slug, $i->from_version, $i->to_version)),
        ];

        $out = [];
        foreach ($sites as $site) {
            foreach ($updates[$site->id] as $u) {
                $out[$site->id][$u['type'] . ':' . $u['id']] = self::assess($site, $u, $context);
            }
        }

        return $out;
    }

    /** @return array<string, array{level: string, reasons: list<array{level: string, text: string}>, summary: ?string}> */
    public static function forSite(Site $site): array
    {
        return self::forSites(collect([$site]))[$site->id] ?? [];
    }

    /**
     * @param array{type: string, id: string, slug: string, name: string, from: string, to: string} $u
     * @param array{directory: array<string, PluginDirectoryEntry>, changelogs: Collection<string, PluginChangelog>, insights: Collection<string, UpdateInsight>} $context
     * @return array{level: string, reasons: list<array{level: string, text: string}>, summary: ?string}
     */
    public static function assess(Site $site, array $u, array $context): array
    {
        $reasons = [];
        $add = function (string $level, string $text) use (&$reasons) {
            $reasons[] = ['level' => $level, 'text' => $text];
        };
        $summary = null;
        $slug = strtolower($u['slug']);

        if ($u['type'] === 'core') {
            if (self::jump($u['from'], $u['to'], 2)) {
                $add('medium', 'Nieuwe hoofdversie van WordPress (' . implode('.', array_slice(explode('.', $u['to']), 0, 2)) . ')');
            }
        } elseif (in_array($u['type'], ['plugin', 'theme'], true)) {
            if (self::jump($u['from'], $u['to'], 1)) {
                $add('medium', 'Grote versiesprong (' . self::major($u['from']) . '.x → ' . self::major($u['to']) . '.x)');
            }
            $updatedAt = self::updatedAt($site, $u);
            if ($updatedAt !== null && $updatedAt->lt(now()->subMonths(self::STALE_MONTHS))) {
                $add('medium', 'Al ' . (int) $updatedAt->diffInMonths(now()) . ' maanden niet bijgewerkt op deze site');
            }
        }

        if ($u['type'] === 'plugin') {
            $entry = $context['directory'][$slug] ?? null;
            if ($entry !== null) {
                if ($entry->closed) {
                    $add('high', 'Gesloten op wordpress.org' . ($entry->closed_reason ? " ({$entry->closed_reason})" : ''));
                } elseif ($entry->last_updated !== null && $entry->last_updated->lt(now()->subYears(PluginDirectory::ABANDONED_YEARS))) {
                    $add('medium', 'Wordt nauwelijks onderhouden (laatste release ' . $entry->last_updated->format('m-Y') . ')');
                }
                // De eisen op wordpress.org horen bij de nieuwste versie; alleen gebruiken als dat de versie van de update is.
                if ($entry->version === $u['to']) {
                    if ($entry->requires_php && $site->php_version && version_compare(self::short($site->php_version), $entry->requires_php, '<')) {
                        $add('high', "Vereist PHP {$entry->requires_php}; de site draait " . self::short($site->php_version));
                    }
                    if ($entry->requires_wp && $site->wp_version && version_compare($site->wp_version, $entry->requires_wp, '<')) {
                        $add('high', "Vereist WordPress {$entry->requires_wp}; de site draait {$site->wp_version}");
                    }
                    if ($entry->tested && $site->wp_version && version_compare(self::short($site->wp_version), self::short($entry->tested), '>')) {
                        $add('low', "Getest tot WordPress {$entry->tested}");
                    }
                }
            }

            $entries = Changelogs::between((array) ($context['changelogs']->get($slug)?->entries ?? []), $u['from'], $u['to']);
            if (count($entries) >= self::MANY_VERSIONS) {
                $add('medium', count($entries) . ' versies in één keer');
            }
            foreach ([['high', self::HIGH_WORDS], ['medium', self::MEDIUM_WORDS]] as [$level, $words]) {
                foreach ($entries as $e) {
                    if (preg_match($words, $e['text'], $m)) {
                        $add($level, 'Changelog ' . $e['version'] . ' noemt "' . mb_strtolower($m[0]) . '"');
                        break 2;
                    }
                }
            }

            $insight = $context['insights']->get(UpdateInsight::key('plugin', $slug, $u['from'], $u['to']));
            if ($insight !== null) {
                $summary = $insight->summary;
                if (in_array($insight->risk, ['medium', 'high'], true)) {
                    $add($insight->risk, 'Claude: ' . ($insight->reason ?: self::LABELS[$insight->risk]));
                }
            }
        }

        $level = 'low';
        foreach ($reasons as $r) {
            if (self::RANK[$r['level']] > self::RANK[$level]) {
                $level = $r['level'];
            }
        }
        usort($reasons, fn ($a, $b) => self::RANK[$b['level']] <=> self::RANK[$a['level']]);

        return ['level' => $level, 'reasons' => $reasons, 'summary' => $summary];
    }

    /** Tekst voor een title-attribuut: elke reden op een eigen regel. */
    public static function tooltip(array $risk): string
    {
        $lines = array_map(fn ($r) => '• ' . $r['text'], $risk['reasons']);
        if ($risk['summary']) {
            array_unshift($lines, $risk['summary']);
        }

        return implode("\n", $lines) ?: 'Geen bijzonderheden gevonden';
    }

    /** Stijgen de eerste $segments delen van het versienummer? */
    private static function jump(string $from, string $to, int $segments): bool
    {
        $a = array_map('intval', array_slice(explode('.', self::short($from, 4)), 0, $segments));
        $b = array_map('intval', array_slice(explode('.', self::short($to, 4)), 0, $segments));

        return $a !== $b && version_compare(implode('.', $b), implode('.', $a), '>');
    }

    private static function major(string $version): int
    {
        return (int) explode('.', $version)[0];
    }

    /** Alleen de cijfers, en hooguit $parts delen: "8.2.12-1+ubuntu" → "8.2". */
    private static function short(string $version, int $parts = 2): string
    {
        preg_match('/^\d+(\.\d+)*/', $version, $m);

        return implode('.', array_slice(explode('.', $m[0] ?? '0'), 0, $parts));
    }

    private static function updatedAt(Site $site, array $u): ?CarbonImmutable
    {
        [$list, $key] = $u['type'] === 'plugin' ? ['plugins', 'file'] : ['themes', 'stylesheet'];
        foreach ((array) (($site->inventory ?? [])[$list] ?? []) as $item) {
            if (is_array($item) && ($item[$key] ?? null) === $u['id'] && is_int($item['updated_at'] ?? null)) {
                return CarbonImmutable::createFromTimestamp($item['updated_at']);
            }
        }

        return null;
    }
}
