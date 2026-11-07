@php
    $feedbackItems = [];
    $seen = [];
    $errorsForToast = collect($errors->all() ?? [])->filter(fn ($value) => is_string($value) && trim($value) !== '')
        ->map(fn ($value) => trim($value))->unique()->values()->all();
    if ($errorsForToast !== []) {
        $feedbackItems[] = ['kind' => 'error', 'title' => 'Please review these fields',
            'message' => $errorsForToast[0], 'remaining' => array_slice($errorsForToast, 1), 'duration' => 0, 'icon' => 'alert'];
        $seen[] = $errorsForToast[0];
    }
    foreach (['error' => 'error', 'warning' => 'warning', 'status' => 'success', 'success' => 'success',
              'info' => 'info', 'message' => 'info'] as $key => $kind) {
        $value = session($key);
        if (! is_string($value) || trim($value) === '' || in_array(trim($value), $seen, true)) continue;
        $value = trim($value);
        $seen[] = $value;
        $feedbackItems[] = ['kind' => $kind,
            'title' => match ($kind) { 'error' => 'Action needs attention', 'warning' => 'Please review',
                                       'success' => 'Confirmed', default => 'Information' },
            'message' => $value, 'remaining' => [], 'duration' => $kind === 'success' ? 6500 : ($kind === 'info' ? 8500 : 0),
            'icon' => match ($kind) { 'success' => 'check-circle', 'info' => 'circle-dot', default => 'alert' }];
    }
@endphp
<div class="global-feedback-region" data-feedback-region aria-label="Platform notifications">
  @foreach($feedbackItems as $feedback)
    <section class="global-feedback-toast global-feedback-{{ $feedback['kind'] }}"
             data-global-toast data-feedback-kind="{{ $feedback['kind'] }}" data-feedback-duration="{{ $feedback['duration'] }}"
             role="{{ in_array($feedback['kind'], ['error', 'warning'], true) ? 'alert' : 'status' }}"
             aria-live="{{ in_array($feedback['kind'], ['error', 'warning'], true) ? 'assertive' : 'polite' }}">
      <span class="global-feedback-symbol" aria-hidden="true"><x-icon :name="$feedback['icon']" size="19"/></span>
      <div class="global-feedback-copy">
        <strong>{{ $feedback['title'] }}</strong>
        <p>{{ $feedback['message'] }}</p>
        @if($feedback['remaining'] !== [])
          <details class="global-feedback-details">
            <summary>View {{ count($feedback['remaining']) }} more {{ count($feedback['remaining']) === 1 ? 'issue' : 'issues' }}</summary>
            <ul>@foreach(array_slice($feedback['remaining'], 0, 8) as $error)<li>{{ $error }}</li>@endforeach</ul>
            @if(count($feedback['remaining']) > 8)<li>Additional fields also need review.</li>@endif
          </details>
        @endif
      </div>
      <button type="button" class="global-feedback-dismiss" data-feedback-dismiss
              aria-label="Dismiss notification" title="Dismiss"><x-icon name="x" size="16"/></button>
    </section>
  @endforeach
</div>
