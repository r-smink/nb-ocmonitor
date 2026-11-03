<?php

namespace App\Http\Controllers;

use App\Models\PagespeedResult;
use App\Models\Site;
use App\Ocm\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PageSpeedController extends Controller
{
    public function index(Request $request): View
    {
        $sites = \App\Models\Tag::filter(Site::query()->with(['tags', 'customer.tags'])->whereNotNull('public_key')->orderBy('name')->get(), $request->integer('tag') ?: null);
        $latest = PagespeedResult::latestPerSite();
        $history = PagespeedResult::query()->where('strategy', 'mobile')->whereNull('error')
            ->where('measured_at', '>=', now()->subDays(120))->orderBy('measured_at')
            ->get(['site_id', 'performance'])->groupBy('site_id')
            ->map(fn ($rows) => $rows->pluck('performance')->filter(fn ($v) => $v !== null)->values()->all());

        $rows = $sites->map(fn (Site $site) => [
            'site' => $site,
            'mobile' => $latest->get($site->id)?->get('mobile'),
            'desktop' => $latest->get($site->id)?->get('desktop'),
            'history' => $history->get($site->id, []),
        ])->sortBy(fn ($r) => $r['mobile']?->performance ?? 1000)->values();

        return view('pagespeed.index', [
            'rows' => $rows,
            'hasKey' => (bool) config('ocm.pagespeed.api_key'),
            'intervalDays' => (int) config('ocm.pagespeed.interval_days'),
        ]);
    }

    public function request(Request $request, Site $site): RedirectResponse
    {
        if ($site->pagespeed_requested_at === null) {
            $site->forceFill(['pagespeed_requested_at' => now()])->save();
            Activity::log('pagespeed_requested', [], $site, $request->user());
        }

        return redirect()->to(url()->previous(route('sites.show', $site)))
            ->with('status', 'PageSpeed-meting aangevraagd voor ' . $site->name . '. Die loopt binnen enkele minuten (mobiel en desktop).');
    }
}
