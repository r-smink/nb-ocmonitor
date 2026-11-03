<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteJob;
use App\Ocm\Updates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;

class UpdateController extends Controller
{
    public const TYPES = ['plugin' => 'Plugins', 'theme' => 'Thema\'s', 'core' => 'WordPress', 'client' => 'OC-plugin'];

    public function index(Request $request): View
    {
        $sites = Site::query()->whereNotNull('public_key')->orderBy('name')->get();
        $open = Updates::openJobs($sites);

        $rows = collect();
        foreach ($sites as $site) {
            foreach ($site->availableUpdates() as $item) {
                $rows->push($item + [
                    'site' => $site,
                    'key' => $site->site_id . '|' . $item['type'] . '|' . $item['id'],
                    'queued' => in_array($item['id'], $open[$site->id][$item['type']] ?? [], true),
                    'blocked' => $site->updatesBlockedReason(),
                ]);
            }
        }

        $filters = [
            'type' => array_key_exists((string) $request->query('type'), self::TYPES) ? (string) $request->query('type') : null,
            'component' => (string) $request->query('component', ''),
            'site' => (string) $request->query('site', ''),
            'q' => trim((string) $request->query('q', '')),
        ];
        $base = $rows->filter(fn ($r) => ($filters['type'] === null || $r['type'] === $filters['type'])
            && ($filters['site'] === '' || $r['site']->site_id === $filters['site'])
            && ($filters['q'] === '' || Str::contains(Str::lower($r['name'] . ' ' . $r['slug'] . ' ' . $r['site']->name . ' ' . $r['site']->host), Str::lower($filters['q']))));
        $filtered = $base->filter(fn ($r) => $filters['component'] === '' || $r['type'] . ':' . $r['slug'] === $filters['component'])
            ->sortBy(fn ($r) => ['core' => 0, 'client' => 1, 'plugin' => 2, 'theme' => 3][$r['type']] . Str::lower($r['name'] . ' ' . $r['site']->name))
            ->values();

        return view('updates.index', [
            'rows' => $filtered,
            'components' => $this->components($base),
            'allComponents' => $this->components($rows),
            'sites' => $sites->filter(fn (Site $s) => $rows->contains(fn ($r) => $r['site']->id === $s->id))->values(),
            'filters' => $filters,
            'counts' => ['plugin' => $rows->where('type', 'plugin')->count(), 'theme' => $rows->where('type', 'theme')->count(), 'core' => $rows->where('type', 'core')->count(), 'client' => $rows->where('type', 'client')->count()],
            'jobs' => SiteJob::query()->with(['site', 'requester'])->whereIn('command', array_values(Updates::COMMANDS))->orderByDesc('id')->limit(25)->get(),
            'hasOpenJobs' => $open !== [],
            'types' => self::TYPES,
        ]);
    }

    /** Bulk: items[] = "site_id|type|id", gecontroleerd tegen de inventaris van elke site. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'max:2000'],
            'items.*' => ['string', 'max:300'],
        ], ['items.required' => 'Selecteer eerst een of meer updates.']);

        $grouped = [];
        foreach ($data['items'] as $item) {
            $parts = explode('|', $item, 3);
            if (count($parts) === 3 && array_key_exists($parts[1], self::TYPES)) {
                $grouped[$parts[0]][$parts[1]][] = $parts[2];
            }
        }

        $queued = 0;
        $siteCount = 0;
        $blocked = [];
        $sites = Site::query()->whereNotNull('public_key')->whereIn('site_id', array_keys($grouped))->get();
        foreach ($sites as $site) {
            if ($reason = $site->updatesBlockedReason()) {
                $blocked[] = $site->name . ': ' . $reason;
                continue;
            }
            $before = $queued;
            foreach ($grouped[$site->site_id] as $type => $ids) {
                $queued += Updates::queue($site, $type, $ids, $request->user());
            }
            $siteCount += $queued > $before ? 1 : 0;
        }

        $requested = count($data['items']);
        $message = "{$queued} update(s) klaargezet op {$siteCount} site(s).";
        if ($queued < $requested) {
            $message .= ' ' . ($requested - $queued) . ' overgeslagen (al in de wachtrij, niet meer beschikbaar of geblokkeerd).';
        }
        $redirect = redirect()->to(url()->previous(route('updates.index')))->with('status', $message);

        return $blocked !== [] ? $redirect->withErrors(['updates' => $blocked]) : $redirect;
    }

    /** Vanaf het sitedetail: één component, of alles van een type (ids leeg + all=1). */
    public function site(Request $request, Site $site): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', 'in:plugin,theme,core,translations,client'],
            'ids' => ['array', 'max:500'],
            'ids.*' => ['string', 'max:255'],
            'all' => ['boolean'],
        ]);
        if ($reason = $site->updatesBlockedReason()) {
            return redirect()->route('sites.show', $site)->withErrors(['updates' => $reason]);
        }

        $ids = $data['ids'] ?? [];
        if (!empty($data['all'])) {
            $ids = collect($site->availableUpdates())->where('type', $data['type'])->pluck('id')->all();
        }
        $queued = Updates::queue($site, $data['type'], $ids, $request->user());

        return redirect()->route('sites.show', $site)->with('status', $queued > 0
            ? "{$queued} update(s) klaargezet. De pagina ververst vanzelf tot ze klaar zijn."
            : 'Niets klaargezet: de update staat al in de wachtrij of is niet meer beschikbaar.');
    }

    /**
     * @param Collection<int, array<string, mixed>> $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function components(Collection $rows): Collection
    {
        return $rows->groupBy(fn ($r) => $r['type'] . ':' . $r['slug'])
            ->map(fn (Collection $group, string $key) => [
                'key' => $key,
                'type' => $group->first()['type'],
                'slug' => $group->first()['slug'],
                'name' => $group->first()['name'],
                'sites' => $group->count(),
                'from' => $group->pluck('from')->unique()->sort(fn ($a, $b) => version_compare($a, $b))->values()->all(),
                'to' => $group->pluck('to')->sort(fn ($a, $b) => version_compare($b, $a))->first(),
                'items' => $group->filter(fn ($r) => !$r['queued'] && $r['blocked'] === null)->pluck('key')->values()->all(),
            ])
            ->sortBy(fn ($c) => sprintf('%05d%s', 99999 - $c['sites'], Str::lower($c['name'])))
            ->values();
    }
}
