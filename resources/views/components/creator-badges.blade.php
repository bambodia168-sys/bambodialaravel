@props(['badges', 'variant' => 'standard'])
@php
  $appearance = \App\Support\CreatorBadgeDisplay::settings();
@endphp
@php
  $badgeVariant = in_array($variant, ['standard', 'compact', 'showcase'], true) ? $variant : 'standard';
@endphp
@if($badges->isNotEmpty())
  <div class="creator-badge-row creator-badge-row--{{ $badgeVariant }} creator-badge-row--{{ $appearance->display_mode }} creator-badge-row--size-{{ $appearance->badge_size }} {{ $appearance->tooltips_enabled ? 'creator-badge-row--tips' : 'creator-badge-row--no-tips' }}" aria-label="Creator achievements">
    @foreach($badges as $badge)
      <span class="creator-badge creator-badge--{{ $badge->tone }} creator-badge--{{ $badgeVariant }}"
            tabindex="0" aria-label="{{ $badge->name }}: {{ $badge->description }}">
        @if(is_file(public_path('images/badges/'.$badge->slug.'.svg')))
          <img class="creator-badge-artwork"
             src="{{ asset('images/badges/'.$badge->slug.'.svg') }}"
             width="100" height="100" alt="" loading="lazy" decoding="async" draggable="false">
        @else
          <span class="creator-badge-fallback creator-badge-icon"><x-icon :name="$badge->icon" size="22"/></span>
        @endif
        @if($appearance->display_mode === 'badge_name')
          <span class="creator-badge-name">{{ $badge->name }}</span>
        @endif
        @if($appearance->tooltips_enabled)
        <span class="creator-badge-tooltip" role="tooltip">
          <strong>{{ $badge->name }}</strong>
          <span>{{ $badge->description }}</span>
        </span>
        @endif
      </span>
    @endforeach
  </div>
@endif
