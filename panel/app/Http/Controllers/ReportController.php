<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\ReportAsset;
use App\Ocm\Activity;
use App\Ocm\Reports\ReportData;
use App\Ocm\Reports\ReportDesign;
use App\Ocm\Reports\ReportPdf;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReportController extends Controller
{
    private const MAX_KB = 10240;

    public function index(): View
    {
        return view('reports.index', [
            'design' => ReportDesign::get(),
            'assets' => ReportAsset::byKind(),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'report_enabled']),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate(['font_heading' => ['nullable', Rule::in(array_keys(ReportDesign::FONTS))], 'font_body' => ['nullable', Rule::in(array_keys(ReportDesign::FONTS))]]);
        ReportDesign::save($request->only(array_keys(ReportDesign::DEFAULTS)));
        Activity::log('report_design_updated');

        return back()->with('status', 'Opmaak opgeslagen.');
    }

    public function upload(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(array_keys(ReportAsset::KINDS))],
            'file' => ['required', 'file', 'max:' . self::MAX_KB],
        ]);
        $file = $request->file('file');
        $bytes = (string) file_get_contents($file->getRealPath());
        $mime = self::sniff($bytes);
        $isFont = str_starts_with($data['kind'], 'font_');

        $allowed = match (true) {
            $isFont => ['font/ttf'],
            $data['kind'] === 'logo' => ['image/png', 'image/jpeg'],
            default => ['image/png', 'image/jpeg', 'application/pdf'],
        };
        if (!in_array($mime, $allowed, true)) {
            throw ValidationException::withMessages(['file' => match (true) {
                $isFont => 'Upload een TrueType-font (.ttf).',
                $data['kind'] === 'logo' => 'Upload een PNG of JPG.',
                default => 'Upload een PDF, JPG of PNG.',
            }]);
        }
        if ($mime === 'application/pdf') {
            try {
                $bytes = ReportPdf::preparePdfBackground($bytes);
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages(['file' => $e->getMessage()]);
            }
        }

        ReportAsset::query()->updateOrCreate(['kind' => $data['kind']], [
            'filename' => mb_substr(basename((string) $file->getClientOriginalName()), 0, 200),
            'mime' => $mime,
            'size' => strlen($bytes),
            'data' => base64_encode($bytes),
        ]);
        Activity::log('report_asset_uploaded', ['kind' => $data['kind'], 'mime' => $mime, 'size' => strlen($bytes)]);

        return back()->with('status', ReportAsset::KINDS[$data['kind']] . ' opgeslagen.');
    }

    public function destroyAsset(string $kind): RedirectResponse
    {
        abort_unless(isset(ReportAsset::KINDS[$kind]), 404);
        ReportAsset::query()->where('kind', $kind)->delete();
        Activity::log('report_asset_deleted', ['kind' => $kind]);

        return back()->with('status', ReportAsset::KINDS[$kind] . ' verwijderd.');
    }

    /** Voorbeeld met demogegevens, of met de echte gegevens van een klant. */
    public function preview(Request $request): Response
    {
        $customer = $request->filled('customer') ? Customer::query()->findOrFail((int) $request->query('customer')) : null;
        $data = $customer
            ? ReportData::build($customer, CarbonImmutable::today()->subMonthsNoOverflow($customer->intervalMonths() ?: 1), CarbonImmutable::today())
            : ReportPdf::demoData();

        return response(ReportPdf::render($data), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="voorbeeld-rapport.pdf"',
        ]);
    }

    /** Bestandstype op basis van de inhoud, niet van de extensie of de browser. */
    private static function sniff(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, '%PDF-') => 'application/pdf',
            str_starts_with($bytes, "\x89PNG\r\n\x1a\n") => 'image/png',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "\x00\x01\x00\x00") || str_starts_with($bytes, 'true') => 'font/ttf',
            default => null,
        };
    }
}
