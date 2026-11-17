<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\MaintenanceRun;
use App\Ocm\Reports\ReportData;
use App\Ocm\Reports\ReportPdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Het laatste onderhoudsrapport, te downloaden vanuit de dashboardwidget op de site van de klant.
 * Onder /api zodat het ook werkt als het panel zelf op IP is afgeschermd; alleen met een geldige,
 * tijdelijke handtekening en alleen als het bij de klant aan staat.
 */
class PublicReportController extends Controller
{
    /** Periode van het laatste afgeronde onderhoud: vanaf de beurt daarvoor tot en met deze. */
    public static function lastPeriod(Customer $customer): ?array
    {
        $done = $customer->runs()->whereNotNull('done_at')->orderByDesc('due_on')->limit(2)->get();
        if ($done->isEmpty()) {
            return null;
        }
        /** @var MaintenanceRun $last */
        $last = $done->first();
        $to = $last->due_on;
        $from = $done->count() > 1 ? $done->last()->due_on->addDay() : $to->subMonthsNoOverflow($customer->intervalMonths() ?: 1);

        return [$from, $to];
    }

    /**
     * Periode van één afgeronde beurt: vanaf de afgeronde beurt daarvoor tot en met deze.
     *
     * @return array{0: \Carbon\CarbonImmutable, 1: \Carbon\CarbonImmutable}|null
     */
    public static function runPeriod(Customer $customer, MaintenanceRun $run): ?array
    {
        if ((int) $run->customer_id !== (int) $customer->id || $run->done_at === null) {
            return null;
        }
        $previous = $customer->runs()->whereNotNull('done_at')->where('due_on', '<', $run->due_on->toDateString())->orderByDesc('due_on')->first();
        $to = $run->due_on;
        $from = $previous instanceof MaintenanceRun ? $previous->due_on->addDay() : $to->subMonthsNoOverflow($customer->intervalMonths() ?: 1);

        return [$from, $to];
    }

    public static function url(Customer $customer): ?string
    {
        if (!$customer->widget_report || self::lastPeriod($customer) === null) {
            return null;
        }

        // Relatief ondertekend en dan het vaste panel-adres ervoor: de plugin accepteert alleen links
        // naar de host van het panel waarmee hij gekoppeld is, ook als de link uit de scheduler komt.
        return rtrim((string) config('app.url'), '/') . URL::temporarySignedRoute('report.public', now()->addDays(40), ['customer' => $customer->id], false);
    }

    /** Ondertekende link naar het rapport van één afgeronde beurt (onderhoudshistorie op de site). */
    public static function runUrl(Customer $customer, MaintenanceRun $run): ?string
    {
        if (!$customer->widget_report || self::runPeriod($customer, $run) === null) {
            return null;
        }

        return rtrim((string) config('app.url'), '/') . URL::temporarySignedRoute('report.public.run', now()->addDays(40), ['customer' => $customer->id, 'run' => $run->id], false);
    }

    public function __invoke(Customer $customer): Response
    {
        $period = self::lastPeriod($customer);
        abort_unless($customer->widget_report && $period !== null, 404);

        return $this->pdf($customer, $period);
    }

    /** Rapport van één beurt; de beurt moet van deze klant zijn en afgerond. */
    public function run(Customer $customer, int $run): Response
    {
        $model = MaintenanceRun::query()->whereKey($run)->where('customer_id', $customer->id)->first();
        $period = $model instanceof MaintenanceRun ? self::runPeriod($customer, $model) : null;
        abort_unless($customer->widget_report && $period !== null, 404);

        return $this->pdf($customer, $period);
    }

    /** @param array{0: mixed, 1: mixed} $period */
    private function pdf(Customer $customer, array $period): Response
    {
        [$from, $to] = $period;
        $pdf = ReportPdf::render(ReportData::build($customer, CarbonImmutable::parse($from), CarbonImmutable::parse($to)));
        $name = 'onderhoudsrapport-' . (Str::slug(str_replace('.', '-', $customer->reportName())) ?: 'klant') . '-' . CarbonImmutable::parse($to)->format('Y-m-d') . '.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $name . '"',
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
