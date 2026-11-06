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

    public static function url(Customer $customer): ?string
    {
        if (!$customer->widget_report || self::lastPeriod($customer) === null) {
            return null;
        }

        // Relatief ondertekend en dan het vaste panel-adres ervoor: de plugin accepteert alleen links
        // naar de host van het panel waarmee hij gekoppeld is, ook als de link uit de scheduler komt.
        return rtrim((string) config('app.url'), '/') . URL::temporarySignedRoute('report.public', now()->addDays(40), ['customer' => $customer->id], false);
    }

    public function __invoke(Customer $customer): Response
    {
        $period = self::lastPeriod($customer);
        abort_unless($customer->widget_report && $period !== null, 404);
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
