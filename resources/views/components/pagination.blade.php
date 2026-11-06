@props(['paginator'])
@if($paginator->lastPage() > 1)
<div class="pager">
  <p>Showing {{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} of {{ $paginator->total() }}</p>
  <div class="pager-controls">
    @if($paginator->onFirstPage())
      <span class="btn subtle disabled"><x-icon name="chevron-left" size="16"/> Previous</span>
    @else
      <a class="btn subtle" href="{{ $paginator->previousPageUrl() }}"><x-icon name="chevron-left" size="16"/> Previous</a>
    @endif
    <span class="page-number">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>
    @if($paginator->hasMorePages())
      <a class="btn subtle" href="{{ $paginator->nextPageUrl() }}">Next <x-icon name="chevron-right" size="16"/></a>
    @else
      <span class="btn subtle disabled">Next <x-icon name="chevron-right" size="16"/></span>
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
