@php
  $page = $tickets->currentPage();
  $last = max(1, $tickets->lastPage());
  $low = max(1, $page - 2);
  $high = min($last, $page + 2);
@endphp
<nav class="support-table-pager" aria-label="Support tickets pagination">
  <div class="support-pager-summary">
    Showing <strong>{{ $tickets->firstItem() ?? 0 }}–{{ $tickets->lastItem() ?? 0 }}</strong> of <strong>{{ $tickets->total() }}</strong> tickets
  </div>
  <div class="support-page-controls">
    @if($tickets->onFirstPage())
      <span class="support-page-link is-disabled" aria-disabled="true"><x-icon name="chevron-left" size="15"/> Previous</span>
    @else
      <a class="support-page-link" href="{{ $tickets->previousPageUrl() }}"><x-icon name="chevron-left" size="15"/> Previous</a>
    @endif
    @if($low > 1)
      <a class="support-page-link" href="{{ $tickets->url(1) }}">1</a>
      @if($low > 2)<span class="support-page-ellipsis" aria-hidden="true">…</span>@endif
    @endif
    @for($i = $low; $i <= $high; $i++)
      @if($i === $page)
        <span class="support-page-link is-active" aria-current="page">{{ $i }}</span>
      @else
        <a class="support-page-link" href="{{ $tickets->url($i) }}">{{ $i }}</a>
      @endif
    @endfor
    @if($high < $last)
      @if($high < $last - 1)<span class="support-page-ellipsis" aria-hidden="true">…</span>@endif
      <a class="support-page-link" href="{{ $tickets->url($last) }}">{{ $last }}</a>
    @endif
    @if($tickets->hasMorePages())
      <a class="support-page-link" href="{{ $tickets->nextPageUrl() }}">Next <x-icon name="chevron-right" size="15"/></a>
    @else
      <span class="support-page-link is-disabled" aria-disabled="true">Next <x-icon name="chevron-right" size="15"/></span>
    @endif
  </div>
  <form method="get" class="support-goto-form">
    @foreach(request()->except('page') as $key => $value)
      @if(is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
    @endforeach
    <label for="support-goto-{{ $management ? 'queue' : 'own' }}">Go To</label>
    <input id="support-goto-{{ $management ? 'queue' : 'own' }}" type="number" name="page" min="1" max="{{ $last }}" value="{{ $page }}" required>
    <button class="btn subtle" type="submit">Go</button>
  </form>
</nav>
