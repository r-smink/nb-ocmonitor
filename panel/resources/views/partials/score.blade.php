@php($scoreLevel = \App\Models\PagespeedResult::level($value))
<span class="score {{ $scoreLevel === 'pending' ? '' : $scoreLevel }} {{ $class ?? '' }}" @isset($title) title="{{ $title }}" @endisset>{{ $value ?? '–' }}</span>
