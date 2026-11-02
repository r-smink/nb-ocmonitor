<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\SiteEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public function index(Request $request): View
    {
        $tab = $request->query('tab') === 'audit' ? 'audit' : 'sites';

        return view('activity.index', [
            'tab' => $tab,
            'events' => $tab === 'sites' ? SiteEvent::query()->with('site')->orderByDesc('occurred_at')->orderByDesc('id')->paginate(100) : null,
            'audit' => $tab === 'audit' ? ActivityLog::query()->with(['user', 'site'])->orderByDesc('id')->paginate(100) : null,
        ]);
    }
}
