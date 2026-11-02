@if ($paginator->hasPages())
  <nav class="pagination" aria-label="Paginering">
    @if ($paginator->onFirstPage())
      <span class="disabled">‹ Vorige</span>
    @else
      <a href="{{ $paginator->previousPageUrl() }}">‹ Vorige</a>
    @endif
    <span class="current">{{ $paginator->currentPage() }}</span>
    @if ($paginator->hasMorePages())
      <a href="{{ $paginator->nextPageUrl() }}">Volgende ›</a>
    @else
      <span class="disabled">Volgende ›</span>
    @endif
  </nav>
@endif
