<?php

namespace App\Http\Controllers;

use App\Models\Site;
use App\Models\Tag;
use App\Ocm\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TagController extends Controller
{
    public function index(): View
    {
        return view('tags.index', ['tags' => Tag::query()->withCount(['sites', 'customers'])->orderBy('name')->get()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40', 'unique:tags,name'],
            'color' => ['required', Rule::in(array_keys(Tag::COLORS))],
        ]);
        $tag = Tag::query()->create($data);
        Activity::log('tag_created', ['tag' => $tag->name]);

        return back()->with('status', "Tag {$tag->name} toegevoegd.");
    }

    public function update(Request $request, Tag $tag): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:40', Rule::unique('tags', 'name')->ignore($tag->id)],
            'color' => ['required', Rule::in(array_keys(Tag::COLORS))],
        ]);
        $tag->update($data);

        return back()->with('status', 'Tag opgeslagen.');
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        Activity::log('tag_deleted', ['tag' => $tag->name]);
        $tag->delete();

        return back()->with('status', 'Tag verwijderd.');
    }

    /** Tags van één site (de tags van de klant komen er vanzelf bij). */
    public function site(Request $request, Site $site): RedirectResponse
    {
        $data = $request->validate(['tags' => ['nullable', 'array'], 'tags.*' => ['integer', 'exists:tags,id']]);
        $site->tags()->sync($data['tags'] ?? []);

        return back()->with('status', 'Tags opgeslagen.');
    }
}
