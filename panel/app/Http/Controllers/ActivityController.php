<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\SiteEvent;
use App\Ocm\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * CSV van het audit log (alleen admins; met de hash-keten, zodat de export controleerbaar is)
     * of van de wijzigingen op de sites, over een periode van maximaal een jaar.
     */
    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'tab' => ['nullable', 'in:sites,audit'],
            'van' => ['nullable', 'date_format:Y-m-d'],
            'tot' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:van'],
        ]);
        $audit = ($data['tab'] ?? 'sites') === 'audit';
        if ($audit) {
            Gate::authorize('manage-users');
        }
        $to = isset($data['tot']) ? CarbonImmutable::parse($data['tot'], 'Europe/Amsterdam')->endOfDay() : CarbonImmutable::now('Europe/Amsterdam');
        $from = isset($data['van']) ? CarbonImmutable::parse($data['van'], 'Europe/Amsterdam')->startOfDay() : $to->subDays(30)->startOfDay();
        $from = max($from, $to->subYear());
        Activity::log('activity_exported', ['tab' => $audit ? 'audit' : 'sites', 'from' => $from->toDateString(), 'to' => $to->toDateString()]);

        $name = ($audit ? 'audit-log' : 'wijzigingen') . '-' . $from->format('Ymd') . '-' . $to->format('Ymd') . '.csv';

        return response()->streamDownload(function () use ($audit, $from, $to) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM, zodat Excel de tekens goed leest
            $range = [$from->utc(), $to->utc()];
            if ($audit) {
                fputcsv($out, ['id', 'tijd_utc', 'gebruiker', 'e-mail', 'site_id', 'site', 'actie', 'details', 'ip', 'prev_hash', 'hash']);
                foreach (ActivityLog::query()->with(['user', 'site'])->whereBetween('created_at', $range)->orderBy('id')->lazy(500) as $row) {
                    fputcsv($out, self::safe([
                        $row->id, $row->getRawOriginal('created_at'), $row->user?->name, $row->user?->email, $row->site?->site_id, $row->site?->name,
                        $row->action, $row->details ? json_encode($row->details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : '', $row->ip, $row->prev_hash, $row->hash,
                    ]));
                }
            } else {
                fputcsv($out, ['tijd_utc', 'site_id', 'site', 'type', 'object', 'van', 'naar', 'bron', 'resultaat']);
                foreach (SiteEvent::query()->with('site')->whereBetween('occurred_at', $range)->orderBy('occurred_at')->orderBy('id')->lazy(500) as $e) {
                    fputcsv($out, self::safe([
                        $e->occurred_at?->utc()->format('Y-m-d H:i:s'), $e->site?->site_id, $e->site?->name, $e->type, $e->object,
                        $e->from_version, $e->to_version, $e->source, $e->result,
                    ]));
                }
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Geen formules in Excel: cellen die met = + - @ beginnen krijgen een apostrof.
     *
     * @param list<mixed> $row
     * @return list<string>
     */
    private static function safe(array $row): array
    {
        return array_map(function ($v) {
            $v = (string) ($v ?? '');

            return $v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'" . $v : $v;
        }, $row);
    }
}
