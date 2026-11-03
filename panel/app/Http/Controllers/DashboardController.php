<?php

namespace App\Http\Controllers;

use App\Models\PagespeedResult;
use App\Models\Site;
use App\Models\SiteEvent;
use App\Ocm\SiteIssues;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request): View
    {
        $sites = \App\Models\Tag::filter(Site::query()->with(['tags', 'customer.tags'])->orderBy('name')->get(), $request->integer('tag') ?: null);
        SiteIssues::load($sites);

        $category = array_key_exists((string) $request->query('cat'), SiteIssues::CATEGORIES) ? (string) $request->query('cat') : null;
        $showInfo = $request->boolean('info');

        $all = collect();
        foreach ($sites as $site) {
            foreach ($site->issues() as $issue) {
                $all->push($issue + ['site' => $site]);
            }
        }
        $order = array_flip(array_keys(SiteIssues::CATEGORIES));
        $all = $all->sortBy(fn ($i) => sprintf('%d%d%s', SiteIssues::LEVEL_RANK[$i['level']], $order[$i['category']], mb_strtolower($i['site']->name)))->values();

        $issues = $all->filter(fn ($i) => ($category === null || $i['category'] === $category) && ($showInfo || $i['level'] !== 'info'))->values();

        $levels = ['crit' => 0, 'warn' => 0, 'ok' => 0, 'pending' => 0];
        foreach ($sites as $site) {
            $levels[$site->level()]++;
        }

        // Updates over alle sites, gegroepeerd per component.
        $updates = collect();
        foreach ($sites as $site) {
            foreach ($site->availableUpdates() as $item) {
                $updates->push($item + ['site' => $site]);
            }
        }
        $topComponents = $updates->groupBy(fn ($u) => $u['type'] . ':' . $u['slug'])
            ->map(fn ($group) => [
                'type' => $group->first()['type'],
                'slug' => $group->first()['slug'],
                'name' => $group->first()['name'],
                'sites' => $group->count(),
                'to' => $group->pluck('to')->sort(fn ($a, $b) => version_compare($b, $a))->first(),
            ])
            ->sortByDesc('sites')->take(8)->values();

        $latestPsi = PagespeedResult::latestPerSite();
        $mobileScores = $latestPsi->map(fn ($r) => $r->get('mobile')?->performance)->filter(fn ($v) => $v !== null);
        $worstPsi = $sites->filter(fn (Site $s) => $latestPsi->get($s->id)?->get('mobile') !== null)
            ->sortBy(fn (Site $s) => $latestPsi[$s->id]['mobile']->performance)
            ->take(6)
            ->map(fn (Site $s) => ['site' => $s, 'mobile' => $latestPsi[$s->id]['mobile'], 'desktop' => $latestPsi[$s->id]->get('desktop')])
            ->values();

        return view('dashboard', [
            'sites' => $sites,
            'levels' => $levels,
            'issues' => $issues,
            'issueCounts' => [
                'crit' => $all->where('level', 'crit')->count(),
                'warn' => $all->where('level', 'warn')->count(),
                'info' => $all->where('level', 'info')->count(),
                'security' => $all->where('category', 'beveiliging')->whereIn('level', ['crit', 'warn'])->count(),
                'security_crit' => $all->where('category', 'beveiliging')->where('level', 'crit')->count(),
                'per_category' => $all->whereIn('level', $showInfo ? ['crit', 'warn', 'info'] : ['crit', 'warn'])->countBy('category')->all(),
            ],
            'category' => $category,
            'showInfo' => $showInfo,
            'updateCounts' => [
                'plugin' => $updates->where('type', 'plugin')->count(),
                'theme' => $updates->where('type', 'theme')->count(),
                'core' => $updates->where('type', 'core')->count(),
                'sites' => $updates->pluck('site.id')->unique()->count(),
            ],
            'topComponents' => $topComponents,
            'avgPsi' => $mobileScores->isNotEmpty() ? (int) round($mobileScores->avg()) : null,
            'worstPsi' => $worstPsi,
            'events' => SiteEvent::query()->with('site')->where('occurred_at', '>=', now()->subDays(2))
                ->whereNotIn('type', ['user.login', 'login.failed_summary'])
                ->orderByDesc('occurred_at')->orderByDesc('id')->limit(15)->get(),
        ]);
    }
}
