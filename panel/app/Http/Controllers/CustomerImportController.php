<?php

namespace App\Http\Controllers;

use App\Ocm\CustomerImport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class CustomerImportController extends Controller
{
    public function form(): View
    {
        return view('customers.import', ['plan' => null, 'data' => '']);
    }

    /** Stap 1: inlezen en laten zien wat er gaat gebeuren. Er wordt nog niets opgeslagen. */
    public function preview(Request $request): View|RedirectResponse
    {
        $request->validate([
            'file' => ['nullable', 'file', 'max:1024', 'mimes:csv,txt,tsv'],
            'data' => ['nullable', 'string', 'max:' . CustomerImport::MAX_BYTES],
        ], ['file.mimes' => 'Upload een CSV-bestand (in Excel: Opslaan als → CSV).']);

        $data = $request->hasFile('file') ? (string) file_get_contents($request->file('file')->getRealPath()) : (string) $request->input('data');
        if (trim($data) === '') {
            return back()->withErrors(['data' => 'Kies een bestand of plak de regels uit Excel.']);
        }

        return view('customers.import', ['plan' => CustomerImport::plan($data), 'data' => $data]);
    }

    /** Stap 2: na bevestigen opnieuw inlezen en opslaan. */
    public function store(Request $request): RedirectResponse
    {
        $request->validate(['data' => ['required', 'string', 'max:' . CustomerImport::MAX_BYTES]]);
        $plan = CustomerImport::plan((string) $request->input('data'));
        if ($plan['customers'] === []) {
            return redirect()->route('customers.import')->withErrors(['data' => $plan['errors'][0] ?? 'Niets om te importeren.']);
        }
        $made = CustomerImport::apply($plan, $request->user());

        return redirect()->route('customers.index')->with('status', sprintf(
            'Import klaar: %d klant(en) toegevoegd, %d aangevuld, %d site(s) toegevoegd. Stel per klant de startdatum in en maak per site een koppelcode.',
            $made['customers'], $plan['counts']['update'], $made['sites']
        ));
    }

    public function template(): Response
    {
        return response(CustomerImport::template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="klanten-import-voorbeeld.csv"',
        ]);
    }
}
