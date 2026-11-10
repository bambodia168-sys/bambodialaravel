@php $rule = $rule ?? null; @endphp
<label for="rule-name-{{ $rule?->id ?? 'new' }}">Rule name</label>
<input id="rule-name-{{ $rule?->id ?? 'new' }}" name="name" type="text" minlength="3" maxlength="100"
  value="{{ $rule?->name ?? '' }}" placeholder="e.g. Repeated sign-in failures" required>
<label for="rule-event-{{ $rule?->id ?? 'new' }}">Recorded audit event</label>
<select id="rule-event-{{ $rule?->id ?? 'new' }}" name="event" required>
  @foreach($monitorEvents as $key => $label)
    <option value="{{ $key }}" @selected(($rule?->event??'')===$key)>{{ $label }}</option>
  @endforeach
</select>
<label for="rule-threshold-{{ $rule?->id ?? 'new' }}">Alert after this many events</label>
<input id="rule-threshold-{{ $rule?->id ?? 'new' }}" name="threshold" type="number"
  min="1" max="100" value="{{ $rule?->threshold ?? 10 }}" required>
<label for="rule-window-{{ $rule?->id ?? 'new' }}">Observation window</label>
<select id="rule-window-{{ $rule?->id ?? 'new' }}" name="window_minutes">
  @foreach($monitorWindows as $minutes)
    <option value="{{ $minutes }}" @selected(($rule?->window_minutes??15)===$minutes)>{{ $minutes }} minutes</option>
  @endforeach
</select>
<label for="rule-cooldown-{{ $rule?->id ?? 'new' }}">Minimum time between alerts</label>
<select id="rule-cooldown-{{ $rule?->id ?? 'new' }}" name="cooldown_minutes">
  @foreach($monitorCooldowns as $minutes)
    <option value="{{ $minutes }}" @selected(($rule?->cooldown_minutes??60)===$minutes)>{{ $minutes===1440?'24 hours':$minutes.' minutes' }}</option>
  @endforeach
</select>
<label for="rule-enabled-{{ $rule?->id ?? 'new' }}">Monitoring status</label>
<select id="rule-enabled-{{ $rule?->id ?? 'new' }}" name="is_enabled">
  <option value="0" @selected(!$rule?->is_enabled)>Off — do not monitor yet</option>
  <option value="1" @selected($rule?->is_enabled)>On — evaluate recorded events</option>
</select>
<p class="hint">Alerts use existing audit events, not malware scanning. Only active super-admins receive an in-app alert. Each rule has a cooldown to prevent duplicate notifications.</p>
