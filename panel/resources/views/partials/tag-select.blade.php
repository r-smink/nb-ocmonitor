@php($tagOptions = \App\Models\Tag::query()->orderBy('name')->get(['id', 'name']))
@if ($tagOptions->isNotEmpty())
  <select name="tag" data-autosubmit aria-label="Tag">
    <option value="">Alle tags</option>
    @foreach ($tagOptions as $tagOption)<option value="{{ $tagOption->id }}" @selected((int) request('tag') === $tagOption->id)>{{ $tagOption->name }}</option>@endforeach
  </select>
@endif
