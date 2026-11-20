@props(['item', 'isSaved' => false, 'labeled' => false])

@php
  $collection = $item instanceof \App\Models\MarketplaceJob ? 'saved-jobs' : 'saved-listings';
  $label = 'Save '.$item->title;
  $action = $isSaved
    ? 'Remove '.$item->title.' from '.str_replace('-', ' ', $collection)
    : $label;
@endphp

@auth
  <form class="save-star-form" method="post" action="{{ route($collection.($isSaved ? '.destroy' : '.store'), $item) }}">
    @csrf
    @if($isSaved)
      @method('DELETE')
    @endif
    <button type="submit" @class(['save-star', 'btn subtle' => $labeled]) aria-label="{{ $label }}"
      aria-pressed="{{ $isSaved ? 'true' : 'false' }}" title="{{ $action }}">
      <x-icon name="star" size="18"/>
      @if($labeled)
        {{ $isSaved ? 'Saved · Remove' : 'Save' }}
      @endif
    </button>
  </form>
@else
  <a href="{{ route('login') }}" @class(['save-star', 'btn subtle' => $labeled])
    aria-label="Sign in to save {{ $item->title }}" title="Sign in to save">
    <x-icon name="star" size="18"/>
    @if($labeled)
      Sign in to save
    @endif
  </a>
@endauth
