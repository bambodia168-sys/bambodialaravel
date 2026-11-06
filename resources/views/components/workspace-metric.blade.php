@props([
    'label',
    'value',
    'icon' => 'activity',
    'href',
    'action' => 'Open workspace',
])
<a {{ $attributes->class(['workspace-overview-metric']) }} href="{{ $href }}"
   aria-label="{{ $label }}: {{ number_format((int)$value) }}. {{ $action }}">
  <span class="workspace-overview-metric-label"><x-icon :name="$icon" size="18"/> {{ $label }}</span>
  <strong>{{ number_format((int)$value) }}</strong>
  <span class="workspace-overview-metric-action">{{ $action }} <x-icon name="chevron-right" size="15"/></span>
</a>
