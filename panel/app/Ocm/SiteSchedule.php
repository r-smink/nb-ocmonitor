<?php

namespace App\Ocm;

use App\Models\Customer;
use App\Models\MaintenanceRun;
use App\Models\Site;
use App\Ocm\Reports\ReportDesign;

/**
 * Stuurt de onderhoudsplanning naar de dashboardwidget "Onderhoud door …" op de site
 * (site.schedule, client-plugin 0.11.0+).
 */
final class SiteSchedule
{
    public const MIN_VERSION = '0.11.0';

    public static function push(Site $site): bool
    {
        if (!$site->isPaired() || $site->plugin_version === null || version_compare($site->plugin_version, self::MIN_VERSION, '<') || !$site->hasScope('maintenance')) {
            return false;
        }
        JobQueue::enqueue($site, 'site.schedule', self::args($site->customer));

        return true;
    }

    public static function pushCustomer(Customer $customer): void
    {
        $customer->load('runs');
        foreach ($customer->sites()->get() as $site) {
            $site->setRelation('customer', $customer);
            self::push($site);
        }
    }

    /** @return array<string, string|null> */
    public static function args(?Customer $customer): array
    {
        $last = $customer?->runs()->whereNotNull('done_at')->orderByDesc('due_on')->first();
        // Voor de klant: de eerstvolgende datum vanaf vandaag die nog niet is afgerond (achterstallige niet tonen).
        $next = null;
        if ($customer?->hasContract()) {
            $today = \Carbon\CarbonImmutable::today();
            $done = $customer->runs()->whereNotNull('done_at')->pluck('due_on')->map(fn ($d) => \Carbon\CarbonImmutable::parse($d)->toDateString())->all();
            foreach ($customer->dueDates($today, $today->addMonthsNoOverflow($customer->intervalMonths() * 2 + 1)) as $date) {
                if (!in_array($date->toDateString(), $done, true)) {
                    $next = $date->toDateString();
                    break;
                }
            }
        }

        return [
            'next' => $next,
            'last' => $last instanceof MaintenanceRun ? $last->due_on->toDateString() : null,
            'contract' => $customer && $customer->hasContract() ? $customer->contractLabel() : null,
            'company' => ReportDesign::get()['company'] ?: 'Next Buzz',
            'contact' => AppSettings::get('widget_contact') ?: null,
        ];
    }
}
