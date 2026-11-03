@if ($tags->isNotEmpty())<span class="tags">@foreach ($tags as $tag)<span class="tag {{ $tag->color }}">{{ $tag->name }}</span>@endforeach</span>@endif
