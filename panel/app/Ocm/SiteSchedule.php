<?php

namespace App\Ocm;

use App\Http\Controllers\PublicReportController;
use App\Models\Customer;
use App\Models\MaintenanceRun;
use App\Models\Site;
use App\Models\SiteJob;
use App\Ocm\Monitoring\Incidents;
use App\Ocm\Reports\ReportDesign;
use Carbon\CarbonImmutable;

/**
 * Stuurt de onderhoudsplanning naar de dashboardwidget "Onderhoud door …" op de site
 * (site.schedule, client-plugin 0.11.0+). Vanaf 0.16.0 ook de status (SSL, uptime) en de
 * onderhoudshistorie; oudere plugins weigeren onbekende velden, dus die krijgen ze niet.
 */
final class SiteSchedule
{
    public const MIN_VERSION = '0.11.0';

    /** Vanaf deze plugin-versie: 'status' en 'reports' in site.schedule. */
    public const STATUS_MIN_VERSION = '0.16.0';

    /** Zoveel rapporten in de historie op de site (nieuwste eerst). */
    public const MAX_REPORTS = 12;

    public static function push(Site $site): bool
    {
        if (!$site->isPaired() || $site->plugin_version === null || version_compare($site->plugin_version, self::MIN_VERSION, '<') || !$site->hasScope('maintenance')) {
            return false;
        }
        $args = self::args($site->customer, $site);
        $job = JobQueue::enqueue($site, 'site.schedule', $args);
        // Staat er nog een opdracht klaar met oudere gegevens, dan die bijwerken (geen tweede opdracht).
        if ($job->status === 'pending' && $job->args !== $args) {
            $job->update(['args' => $args]);
        }

        return true;
    }

    /** Na een wijziging in SSL of uptime: alleen voor plugins die de status tonen. */
    public static function pushStatus(Site $site): bool
    {
        if (!self::supportsStatus($site)) {
            return false;
        }

        return self::push($site);
    }

    public static function supportsStatus(?Site $site): bool
    {
        return $site !== null && $site->plugin_version !== null && version_compare($site->plugin_version, self::STATUS_MIN_VERSION, '>=');
    }

    public static function pushCustomer(Customer $customer): void
    {
        $customer->load('runs');
        foreach ($customer->sites()->get() as $site) {
            $site->setRelation('customer', $customer);
            self::push($site);
        }
    }

    /**
     * Zonder $site (of voor een plugin ouder dan STATUS_MIN_VERSION) alleen de velden die elke
     * plugin vanaf MIN_VERSION kent.
     *
     * @return array<string, mixed>
     */
    public static function args(?Customer $customer, ?Site $site = null): array
    {
        $last = $customer?->runs()->whereNotNull('done_at')->orderByDesc('due_on')->first();
        // Voor de klant: de eerstvolgende datum vanaf vandaag die nog niet is afgerond (achterstallige niet tonen).
        $next = null;
        if ($customer?->hasContract()) {
            $today = CarbonImmutable::today();
            $customer->load('runs');
            $done = $customer->runs->whereNotNull('done_at')->map(fn ($r) => $r->due_on->toDateString())->all();
            foreach ($customer->plannedDates($today, $today->addMonthsNoOverflow($customer->intervalMonths() * 2 + 1)) as $item) {
                if (!in_array($item['due']->toDateString(), $done, true)) {
                    $next = $item['date']->toDateString();
                    break;
                }
            }
        }

        $args = [
            'next' => $next,
            'last' => $last instanceof MaintenanceRun ? $last->due_on->toDateString() : null,
            'contract' => $customer && $customer->hasContract() ? $customer->contractLabel() : null,
            'company' => ReportDesign::get()['company'] ?: 'Next Buzz',
            'contact' => AppSettings::get('widget_contact') ?: null,
        ] + self::branding() + self::report($customer, $last);

        if (self::supportsStatus($site)) {
            $args['status'] = self::status($site);
            $args['reports'] = self::reports($customer);
        }

        return $args;
    }

    /**
     * SSL en uptime zoals de monitor (ocm:monitor) ze het laatst zag. null = (nog) niet gemeten.
     *
     * @return array{ssl: array{ok: ?bool, expires: ?string}, uptime: array{ok: ?bool, percent: ?float, down_since: ?string}}
     */
    public static function status(Site $site): array
    {
        $uptime = ['ok' => null, 'percent' => null, 'down_since' => null];
        if ($site->uptime_state !== null) {
            $now = CarbonImmutable::now();
            $percent = Incidents::period($site, $now->subDays(30), $now)['percent'];
            $down = $site->uptime_state === 'down';
            $uptime = [
                'ok' => !$down,
                'percent' => $percent !== null ? (float) $percent : null,
                'down_since' => $down && $site->down_since !== null ? $site->down_since->toIso8601String() : null,
            ];
        }

        return ['ssl' => self::sslStatus($site), 'uptime' => $uptime];
    }

    /** @return array{ok: ?bool, expires: ?string} */
    public static function sslStatus(Site $site): array
    {
        if ($site->ssl_checked_at === null) {
            return ['ok' => null, 'expires' => null];
        }
        $expires = $site->ssl_expires_at;

        return [
            'ok' => $site->ssl_error === null && $expires !== null && $expires->isFuture(),
            'expires' => $expires?->copy()->timezone('Europe/Amsterdam')->toDateString(),
        ];
    }

    /**
     * Onderhoudshistorie: per afgeronde beurt een ondertekende downloadlink, alleen als het
     * rapport in de widget bij de klant aan staat.
     *
     * @return list<array{date: string, label: string, url: string}>
     */
    public static function reports(?Customer $customer): array
    {
        if ($customer === null || !$customer->widget_report) {
            return [];
        }
        $out = [];
        foreach ($customer->runs()->whereNotNull('done_at')->orderByDesc('due_on')->limit(self::MAX_REPORTS)->get() as $run) {
            $url = PublicReportController::runUrl($customer, $run);
            if ($url !== null) {
                $out[] = ['date' => $run->due_on->toDateString(), 'label' => 'Onderhoud ' . $run->due_on->locale('nl')->translatedFormat('F Y'), 'url' => $url];
            }
        }

        return $out;
    }

    /** @return array<string, string> Next Buzz-kleuren uit de rapportopmaak */
    private static function branding(): array
    {
        $design = ReportDesign::get();

        return ['color' => $design['color_primary'], 'accent' => $design['color_accent']];
    }

    /** @return array<string, string> downloadknop, alleen als dat bij de klant aan staat */
    private static function report(?Customer $customer, ?MaintenanceRun $last): array
    {
        $url = $customer ? PublicReportController::url($customer) : null;
        if ($url === null || $last === null) {
            return [];
        }

        return ['report_url' => $url, 'report_label' => 'Rapport ' . $last->due_on->locale('nl')->translatedFormat('F Y') . ' downloaden'];
    }
}
