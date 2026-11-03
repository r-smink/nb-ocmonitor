<?php

namespace App\Ocm;

use App\Models\Site;
use App\Models\User;

/**
 * Bepaalt de actieknop bij een aandachtspunt (takenlijst op het Overzicht). Knoppen sturen naar de
 * bestaande POST-routes; wat al in de wachtrij staat of al is aangevraagd, toont een "gedaan"-staat.
 *
 * Resultaat:
 *  - ['kind' => 'form', 'label', 'action' (url), 'fields' => [naam => waarde|list], 'confirm' => ?string]
 *  - ['kind' => 'link', 'label', 'url']
 *  - ['kind' => 'done', 'label']
 */
final class IssueActions
{
    /**
     * @param array<string, mixed> $issue
     * @param array<int, array<string, string[]>> $open Updates::openJobs(): site-id => type => ids in de wachtrij
     * @return array<string, mixed>
     */
    public static function for(array $issue, Site $site, array $open, ?User $user): array
    {
        $manage = $user !== null && $user->can('manage-sites');
        $view = ['kind' => 'link', 'label' => 'Bekijken', 'url' => route('sites.show', $site)];

        if (!$site->isPaired()) {
            return ['kind' => 'link', 'label' => 'Koppelen', 'url' => route('sites.show', $site)];
        }

        if ($manage && Menu::enabled('updates') && (isset($issue['updates']) || isset($issue['component']))) {
            return self::updates($issue, $site, $open) ?? $view;
        }

        if ($manage && isset($issue['visual'])) {
            return ['kind' => 'form', 'label' => 'Bekeken', 'action' => route('sites.visual.review', [$site, $issue['visual']]), 'fields' => [], 'confirm' => null];
        }

        if ($manage && !empty($issue['pagespeed']) && Menu::enabled('pagespeed')) {
            return $site->pagespeed_requested_at !== null
                ? ['kind' => 'done', 'label' => 'Aangevraagd']
                : ['kind' => 'form', 'label' => 'Meten', 'action' => route('sites.pagespeed', $site), 'fields' => [], 'confirm' => null];
        }

        if (isset($issue['license']) && Menu::enabled('licenses')) {
            return ['kind' => 'link', 'label' => 'Licenties', 'url' => route('licenses.index')];
        }

        if (!empty($issue['two_factor']) && Menu::enabled('wp-users')) {
            return ['kind' => 'link', 'label' => '2FA instellen', 'url' => route('wp-users.index')];
        }

        return $view;
    }

    /**
     * @param array<string, mixed> $issue
     * @param array<int, array<string, string[]>> $open
     * @return array<string, mixed>|null
     */
    private static function updates(array $issue, Site $site, array $open): ?array
    {
        if ($site->updatesBlockedReason() !== null) {
            return null;
        }
        $types = (array) ($issue['updates'] ?? []);
        $component = $issue['component'] ?? null;

        $matching = collect($site->availableUpdates())->filter(fn (array $u) => $component !== null
            ? $u['type'] === $component['type'] && strtolower($u['slug']) === strtolower($component['slug'])
            : in_array($u['type'], $types, true));
        if ($matching->isEmpty()) {
            return null;
        }
        $todo = $matching->reject(fn (array $u) => in_array($u['id'], $open[$site->id][$u['type']] ?? [], true));
        if ($todo->isEmpty()) {
            return ['kind' => 'done', 'label' => 'In wachtrij'];
        }

        $count = $todo->count();
        $what = $count === 1 ? $todo->first()['name'] . ' ' . $todo->first()['to'] : "{$count} updates";
        $confirm = "{$what} klaarzetten op {$site->name}?";
        if ($todo->contains('type', 'core')) {
            $confirm .= ' Voor WordPress zelf is er geen automatische rollback.';
        }

        return [
            'kind' => 'form',
            'label' => $count === 1 ? 'Bijwerken' : "Bijwerken ({$count})",
            'action' => route('updates.store'),
            'fields' => ['items' => $todo->map(fn (array $u) => $site->site_id . '|' . $u['type'] . '|' . $u['id'])->values()->all()],
            'confirm' => $confirm,
        ];
    }
}
