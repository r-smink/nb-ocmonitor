<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\SiteNote;
use App\Ocm\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Notities per site. Toevoegen en vastzetten: beheerders; verwijderen: de schrijver of een admin. */
class SiteNoteController extends Controller
{
    public function store(Request $request, Site $site): RedirectResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000'], 'pinned' => ['nullable', 'boolean']]);
        $note = $site->notes()->create(['body' => trim($data['body']), 'pinned' => !empty($data['pinned']), 'user_id' => $request->user()->id]);
        Activity::log('site_note_added', ['note_id' => $note->id, 'pinned' => $note->pinned], $site);

        return redirect()->to(route('sites.show', $site) . '#notities')->with('status', 'Notitie toegevoegd.');
    }

    public function pin(Request $request, Site $site, SiteNote $note): RedirectResponse
    {
        abort_unless($note->site_id === $site->id, 404);
        $note->update(['pinned' => !$note->pinned]);

        return redirect()->to(route('sites.show', $site) . '#notities')->with('status', $note->pinned ? 'Notitie vastgezet.' : 'Notitie losgemaakt.');
    }

    public function destroy(Request $request, Site $site, SiteNote $note): RedirectResponse
    {
        abort_unless($note->site_id === $site->id, 404);
        abort_unless($request->user()->isAdmin() || $note->user_id === $request->user()->id, 403);
        Activity::log('site_note_deleted', ['note_id' => $note->id, 'body' => mb_substr($note->body, 0, 200)], $site);
        $note->delete();

        return redirect()->to(route('sites.show', $site) . '#notities')->with('status', 'Notitie verwijderd.');
    }
}
