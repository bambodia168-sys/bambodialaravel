@php
  $isOwner = (int)$ticket->user_id === (int)auth()->id();
  $mayReveal = \App\Support\SupportTicketSecrets::mayReveal(auth()->user(), $ticket);
@endphp
<section class="panel ticket-private-vault-panel" aria-labelledby="ticket-private-vault-heading">
  <div class="panel-head">
    <div>
      <h2 id="ticket-private-vault-heading"><x-icon name="shield-check" size="20"/> Encrypted temporary access</h2>
      <p class="text-muted">Credentials are encrypted separately from messages and attachments. Values never appear in email or conversation text.</p>
    </div>
  </div>
  @forelse($ticket->credentials as $credential)
    <div class="ticket-secret-record">
      <div>
        <strong>{{ $credential->label }}</strong>
        <div class="text-muted">
          {{ $credential->active() ? 'Available until '.$credential->expires_at->format('d M Y H:i') : 'Expired or revoked' }}
          · {{ $credential->view_count }} authorized reveal(s)
        </div>
      </div>
      <div class="ticket-secret-actions">
        @if($credential->active() && $mayReveal)
          <button type="button" class="btn subtle compact"
            data-credential-reveal="{{ route('support.credentials.reveal', $credential->public_id) }}"
            aria-label="Reveal {{ $credential->label }}"><x-icon name="eye" size="16"/> Reveal / Copy</button>
        @endif
        @if($credential->active() && ($isOwner || $mayReveal))
          <form method="post" action="{{ route('support.credentials.revoke', $credential->public_id) }}">
            @csrf @method('DELETE')
            <button class="btn subtle compact" type="submit"
              onclick="return confirm('Revoke and erase this shared credential?')"><x-icon name="trash-2" size="16"/> Revoke</button>
          </form>
        @endif
      </div>
    </div>
  @empty
    <p class="text-muted">No temporary credentials have been shared.</p>
  @endforelse
  @if($isOwner && $ticket->status !== 'closed')
    <button class="btn subtle" type="button" data-open-secure-fields>
      <x-icon name="lock-keyhole" size="16"/> Share temporary credentials
    </button>
  @elseif($isStaff && ! $mayReveal)
    <p class="text-muted">Only the assigned support agent or an authorized administrator can reveal shared credentials.</p>
  @endif
  <div class="ticket-secret-revealed" data-revealed-vault hidden>
    <div class="ticket-secure-panel-heading">
      <x-icon name="eye" size="17"/>
      <strong>Authorized reveal — clears from the screen after 30 seconds</strong>
      <button type="button" data-hide-revealed class="btn subtle compact">Hide now</button>
    </div>
    @foreach(['username'=>'Username','password'=>'Password','key'=>'API key / token','url'=>'Portal URL'] as $field=>$label)
      <div class="ticket-secret-view-row" data-secret-row="{{ $field }}" hidden>
        <label for="ticket-secret-revealed-{{ $field }}">{{ $label }}</label>
        <input id="ticket-secret-revealed-{{ $field }}" type="text"
          data-secret-value="{{ $field }}" readonly autocomplete="off">
        <button class="btn subtle compact" type="button" data-secret-copy="{{ $field }}"
          aria-label="Copy {{ $label }}"><x-icon name="copy" size="16"/> Copy</button>
      </div>
    @endforeach
    <p class="text-muted">Access is logged. Never paste credentials into regular replies, emails or screenshots. Rotate credentials after troubleshooting.</p>
  </div>
  <p class="ticket-secret-error ticket-field-error" data-secret-error hidden role="alert"></p>
</section>
@if($isOwner && $ticket->status !== 'closed')
  <section class="panel support-ticket-panel ticket-secure-share-section" id="ticket-secret-share-section">
    <h2>Temporary access for support</h2>
    <p class="text-muted">Only provide an account or scoped token created specifically for troubleshooting.</p>
    <form method="post" action="{{ route('support.credentials.store', $ticket->public_id) }}" autocomplete="off">
      @csrf
      @include('support.credential-fields')
      <div class="support-form-actions">
        <button type="submit" class="btn primary"><x-icon name="shield-check" size="16"/> Encrypt & share access</button>
      </div>
    </form>
  </section>
@endif
