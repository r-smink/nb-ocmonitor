<?php

namespace App\Ocm\Alerts;

use App\Models\Alert;
use App\Models\Site;
use App\Models\User;
use App\Ocm\SiteIssues;
use Carbon\CarbonInterface;

/**
 * Wie wat krijgt: voorkeuren van een portalgebruiker of een kanaal (Slack/Teams/webhook).
 *
 * min_level: crit (alleen kritiek) of warn (ook aandacht). categories/tags/customers leeg = alles.
 * Stille uren (alleen gebruikers): niets behalve kritiek (als quiet_crit), de rest komt erna.
 */
final class AlertFilter
{
    public const LEVELS = ['crit' => 'Alleen kritiek', 'warn' => 'Kritiek en aandacht'];

    /** Standaard: updates zijn dagelijkse kost, die melden we niet tenzij iemand dat aanzet. */
    public const DEFAULT_CATEGORIES = ['beschikbaarheid', 'beveiliging', 'prestaties', 'koppeling'];

    /** @param array<string, mixed> $prefs */
    private function __construct(public readonly array $prefs)
    {
    }

    /** @param array<string, mixed>|null $raw */
    public static function from(?array $raw, bool $enabledByDefault = true): self
    {
        return new self(self::normalize($raw ?? ['enabled' => $enabledByDefault]));
    }

    public static function forUser(User $user): self
    {
        // Nog niets ingesteld: beheerders krijgen kritieke meldingen per e-mail, alleen-lezen niets.
        return self::from($user->alert_prefs, $user->canManageSites());
    }

    /**
     * @param array<string, mixed> $raw
     * @return array<string, mixed>
     */
    public static function normalize(array $raw): array
    {
        $time = fn ($v) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : null;
        $ids = fn ($v) => array_values(array_unique(array_map('intval', array_filter((array) $v, 'is_numeric'))));

        return [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'min_level' => isset(self::LEVELS[$raw['min_level'] ?? '']) ? $raw['min_level'] : 'crit',
            'categories' => array_key_exists('categories', $raw)
                ? array_values(array_intersect(array_keys(SiteIssues::CATEGORIES), (array) $raw['categories']))
                : self::DEFAULT_CATEGORIES,
            'tags' => $ids($raw['tags'] ?? []),
            'customers' => $ids($raw['customers'] ?? []),
            'resolved' => (bool) ($raw['resolved'] ?? true),
            'quiet_from' => $time($raw['quiet_from'] ?? null),
            'quiet_to' => $time($raw['quiet_to'] ?? null),
            'quiet_crit' => (bool) ($raw['quiet_crit'] ?? true),
        ];
    }

    /**
     * Voorkeuren uit een formulier (partials/alert-prefs). Geen categorie aangevinkt = alle categorieën.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function fromInput(array $input): array
    {
        return self::normalize([
            'enabled' => !empty($input['enabled']),
            'min_level' => $input['min_level'] ?? 'crit',
            'categories' => (array) ($input['categories'] ?? []),
            'tags' => (array) ($input['tags'] ?? []),
            'customers' => (array) ($input['customers'] ?? []),
            'resolved' => !empty($input['resolved']),
            'quiet_from' => $input['quiet_from'] ?? null,
            'quiet_to' => $input['quiet_to'] ?? null,
            'quiet_crit' => !empty($input['quiet_crit']),
        ]);
    }

    /** Korte omschrijving, bijv. "kritiek · beveiliging, beschikbaarheid · stil 22:00-07:00". */
    public function summary(): string
    {
        if (!$this->prefs['enabled']) {
            return 'uit';
        }
        $parts = [$this->prefs['min_level'] === 'crit' ? 'alleen kritiek' : 'kritiek en aandacht'];
        $cats = $this->prefs['categories'];
        $parts[] = $cats === [] || count($cats) === count(SiteIssues::CATEGORIES) ? 'alle categorieën' : implode(', ', array_map(fn ($c) => mb_strtolower(SiteIssues::CATEGORIES[$c]), $cats));
        if ($this->prefs['tags'] !== [] || $this->prefs['customers'] !== []) {
            $parts[] = 'selectie van sites';
        }
        if ($this->prefs['quiet_from'] && $this->prefs['quiet_to']) {
            $parts[] = 'stil ' . $this->prefs['quiet_from'] . '-' . $this->prefs['quiet_to'];
        }

        return implode(' · ', $parts);
    }

    public function enabled(): bool
    {
        return $this->prefs['enabled'];
    }

    /** Valt dit incident (op zijn huidige niveau) binnen de voorkeuren? */
    public function matches(Alert $alert, Site $site): bool
    {
        if (!$this->prefs['enabled'] || ($alert->level !== 'crit' && $this->prefs['min_level'] === 'crit')) {
            return false;
        }
        if ($this->prefs['categories'] !== [] && !in_array($alert->category, $this->prefs['categories'], true)) {
            return false;
        }
        if ($this->prefs['tags'] === [] && $this->prefs['customers'] === []) {
            return true;
        }

        // Tags of klanten: de site hoeft maar aan één van beide te voldoen.
        return in_array((int) $site->customer_id, $this->prefs['customers'], true)
            || $site->allTags()->pluck('id')->intersect($this->prefs['tags'])->isNotEmpty();
    }

    /** Mag dit niveau nu worden verstuurd (stille uren, Europe/Amsterdam)? */
    public function allowsNow(string $level, CarbonInterface $now): bool
    {
        [$from, $to] = [$this->prefs['quiet_from'], $this->prefs['quiet_to']];
        if ($from === null || $to === null || $from === $to) {
            return true;
        }
        $t = $now->copy()->timezone('Europe/Amsterdam')->format('H:i');
        $quiet = $from < $to ? ($t >= $from && $t < $to) : ($t >= $from || $t < $to);

        return !$quiet || ($level === 'crit' && $this->prefs['quiet_crit']);
    }
}
