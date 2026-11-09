@props(['badges'])
@if($badges->isNotEmpty())
  <div class="creator-badge-row" aria-label="Creator achievements">
    @foreach($badges as $badge)
      <span class="creator-badge creator-badge--{{ $badge->tone }}" tabindex="0" aria-label="{{ $badge->name }}: {{ $badge->description }}">
        <span class="creator-badge-icon"><x-icon :name="$badge->icon" size="15"/></span>
        <span class="creator-badge-name">{{ $badge->name }}</span>
        <span class="creator-badge-tooltip" role="tooltip"><strong>{{ $badge->name }}</strong><span>{{ $badge->description }}</span></span>
      </span>
    @endforeach
  </div>
@endif
