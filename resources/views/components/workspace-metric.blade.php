@props([
    'label',
    'value',
    'icon' => 'activity',
    'href' => null,
    'action' => 'Open workspace',
])
@if($href)
<a {{ $attributes->class(['workspace-overview-metric']) }} href="{{ $href }}"
   aria-label="{{ $label }}: {{ number_format((int)$value) }}. {{ $action }}">
@else
<div {{ $attributes->class(['workspace-overview-metric']) }}>
@endif
  <span class="workspace-overview-metric-label"><x-icon :name="$icon" size="18"/> {{ $label }}</span>
  <strong>{{ number_format((int)$value) }}</strong>
  @if($href)
    <span class="workspace-overview-metric-action">{{ $action }}</span>
  @endif
@if($href)
</a>
@else
</div>
@endif
