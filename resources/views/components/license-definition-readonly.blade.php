@props(['definition'=>null,'label'=>null])
@if($definition)
  <details class="license-definition-readonly">
    <summary><x-icon name="file-check" size="16"/> {{ $label ?: $definition->tierLabel() }} · Definition v{{ $definition->version }}</summary>
    <div class="license-definition-readonly-body">
      <p>These are the usage rights tied to this product license. Support duration is managed separately.</p>
      @if($definition->tier === null)<p>This legacy definition predates explicit type classification. Its issued terms remain unchanged.</p>@endif
      <h4>Permissions granted</h4>
      <div class="license-definition-readonly-copy">{{ $definition->permissions }}</div>
      <h4>Restrictions and prohibited uses</h4>
      <div class="license-definition-readonly-copy">{{ $definition->restrictions }}</div>
      @if($definition->status === 'active')
        <a class="btn subtle compact" href="{{ $definition->publicUrl() }}" target="_blank" rel="noopener noreferrer"><x-icon name="external-link" size="15"/> Read published license definition</a>
      @endif
    </div>
  </details>
@endif
