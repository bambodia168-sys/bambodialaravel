@props(['paginator', 'always' => false])
@if($always || $paginator->lastPage() > 1)
<div class="pager">
  <p>Showing {{ $paginator->firstItem() ?? 0 }}–{{ $paginator->lastItem() ?? 0 }} of {{ $paginator->total() }}</p>
  <div class="pager-controls">
    @if($paginator->onFirstPage())
      <span class="btn subtle disabled"><x-icon name="chevron-left" size="16"/> Previous</span>
    @else
      <a class="btn subtle" href="{{ $paginator->previousPageUrl() }}"><x-icon name="chevron-left" size="16"/> Previous</a>
    @endif
    <span class="page-number">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
    @if($paginator->hasMorePages())
      <a class="btn subtle" href="{{ $paginator->nextPageUrl() }}">Next</a>
    @else
      <span class="btn subtle disabled">Next</span>
    @endif
    <form method="get" class="go-form">
      @foreach(request()->except($paginator->getPageName()) as $key => $value)
        @if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
      @endforeach
      <label for="go-page-{{ $paginator->getPageName() }}">Go To</label>
      <input id="go-page-{{ $paginator->getPageName() }}" name="{{ $paginator->getPageName() }}" type="number" min="1" max="{{ $paginator->lastPage() }}" value="{{ $paginator->currentPage() }}" required>
      <button class="btn subtle" type="submit">Go</button>
    </form>
  </div>
</div>
@endif
