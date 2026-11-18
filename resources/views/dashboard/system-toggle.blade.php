<form method="post" action="{{ $action }}" class="system-toggle-form">
  @csrf @method('PATCH')
  <input type="hidden" name="enabled" value="{{ $enabled ? '0' : '1' }}">
  <button type="submit" class="system-switch" role="switch" aria-checked="{{ $enabled ? 'true' : 'false' }}"
    aria-label="{{ $label }}" @disabled($unavailable ?? false)>
    <span class="system-switch-track" aria-hidden="true"></span>
    <span>{{ $enabled ? 'On' : 'Off' }}</span>
  </button>
</form>
