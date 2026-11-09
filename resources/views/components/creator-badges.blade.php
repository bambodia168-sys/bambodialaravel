@props(['badges', 'variant' => 'standard'])
@php
  $badgeVariant = in_array($variant, ['standard', 'compact', 'showcase'], true) ? $variant : 'standard';
@endphp
@if($badges->isNotEmpty())
  <div class="creator-badge-row creator-badge-row--{{ $badgeVariant }}" aria-label="Creator achievements">
    @foreach($badges as $badge)
      <span class="creator-badge creator-badge--{{ $badge->tone }} creator-badge--{{ $badgeVariant }}"
            tabindex="0" aria-label="{{ $badge->name }}: {{ $badge->description }}">
        <img class="creator-badge-artwork"
             src="{{ asset('images/badges/'.$badge->slug.'.svg') }}"
             width="100" height="100" alt="" loading="lazy" decoding="async" draggable="false">
        <span class="creator-badge-name">{{ $badge->name }}</span>
        <span class="creator-badge-tooltip" role="tooltip">
          <strong>{{ $badge->name }}</strong>
          <span>{{ $badge->description }}</span>
        </span>
      </span>
    @endforeach
  </div>
@endif
