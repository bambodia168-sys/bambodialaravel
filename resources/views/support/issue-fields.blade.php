@php
  $currentIssue = old('issue_type', $ticket->issue_type ?? 'general');
  $currentRelated = old('related_item', isset($ticket) && $ticket->context_type && $ticket->context_id
    ? $ticket->context_type.':'.$ticket->context_id : '');
@endphp
<div class="support-issue-grid">
  <div>
    <label for="ticket-issue-type">What do you need help with?</label>
    <select id="ticket-issue-type" name="issue_type" required>
      @foreach(\App\Support\SupportTicketContext::ISSUES as $code => $label)
        <option value="{{ $code }}" @selected($currentIssue === $code)>{{ $label }}</option>
      @endforeach
    </select>
    @error('issue_type')<p class="ticket-field-error" role="alert">{{ $message }}</p>@enderror
  </div>
  <div>
    <label for="ticket-related-item">Related purchase, listing or job (optional)</label>
    <select id="ticket-related-item" name="related_item">
      <option value="">General ticket — no related item</option>
      @foreach($relatedChoices ?? [] as $group => $choices)
        @if(count($choices))
          <optgroup label="{{ $group }}">
            @foreach($choices as $item)
              <option value="{{ $item['value'] }}" @selected($currentRelated === $item['value'])>{{ $item['label'] }}</option>
            @endforeach
          </optgroup>
        @endif
      @endforeach
    </select>
    @error('related_item')<p class="ticket-field-error" role="alert">{{ $message }}</p>@enderror
  </div>
</div>
<p class="text-muted">Choose a Product, Service, Project, Freelance listing, Job or verified order available to your account. Leave blank for installation, licensing, bug reports or general assistance.</p>
