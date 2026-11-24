@php
  $isOwner = (int) $ticket->user_id === (int) auth()->id();
  $mayReveal = \App\Support\SupportTicketSecrets::mayReveal(auth()->user(), $ticket);
@endphp
<section class="panel ticket-private-vault-panel" aria-labelledby="ticket-private-vault-heading">
  <div class="ticket-vault-header">
    <div class="ticket-vault-header-main">
      <span class="ticket-vault-icon" aria-hidden="true"><x-icon name="shield-check" size="21"/></span>
      <div>
        <div class="ticket-vault-eyebrow">PRIVATE · CONTROLLED ACCESS</div>
        <h2 id="ticket-private-vault-heading">Encrypted temporary access</h2>
        <p>Credentials stay separate from messages and attachments. They are never included in emails or conversation text.</p>
      </div>
    </div>
    <span class="ticket-vault-header-badge"><x-icon name="lock-keyhole" size="14"/> Encrypted</span>
  </div>

  <div class="ticket-secret-records">
    @forelse($ticket->credentials as $credential)
      <article class="ticket-secret-record {{ $credential->active() ? 'is-active' : 'is-expired' }}">
        <div class="ticket-secret-record-main">
          <div class="ticket-secret-record-title">
            <span class="ticket-vault-record-icon" aria-hidden="true"><x-icon name="key-round" size="18"/></span>
            <div>
              <h3>{{ $credential->label }}</h3>
              <span class="ticket-vault-status {{ $credential->active() ? 'is-active' : 'is-expired' }}">
                {{ $credential->active() ? 'Active' : 'Expired or revoked' }}
              </span>
            </div>
          </div>
          <div class="ticket-secret-record-meta">
            <span><x-icon name="clock" size="15"/>
              {{ $credential->active() ? 'Expires '.$credential->expires_at->format('d M Y · H:i') : 'Access no longer available' }}
            </span>
            <span><x-icon name="eye" size="15"/> {{ $credential->view_count }} authorized reveal(s)</span>
          </div>
        </div>
        <div class="ticket-secret-actions">
          @if($credential->active() && $mayReveal)
            <button type="button" class="btn subtle compact"
              data-credential-reveal="{{ route('support.credentials.reveal', $credential->public_id) }}"
              data-reveal-label="{{ $credential->label }}"
              aria-label="Reveal {{ $credential->label }}">
              <x-icon name="eye" size="16"/> Reveal / Copy
            </button>
          @endif
          @if($credential->active() && ($isOwner || $mayReveal))
            <form method="post" action="{{ route('support.credentials.revoke', $credential->public_id) }}">
              @csrf @method('DELETE')
              <button class="btn subtle compact" type="submit"
                onclick="return confirm('Revoke and erase this shared credential?')">
                <x-icon name="trash-2" size="16"/> Revoke
              </button>
            </form>
          @endif
        </div>
      </article>
    @empty
      <div class="ticket-vault-empty">
        <x-icon name="lock-keyhole" size="22"/>
        <p>No temporary credentials have been shared for this ticket.</p>
      </div>
    @endforelse
  </div>

  @if($isOwner && $ticket->status !== 'closed')
    <div class="ticket-vault-owner-actions">
      <button class="btn subtle" type="button" data-open-secure-fields>
        <x-icon name="lock-keyhole" size="16"/> Share temporary credentials
      </button>
    </div>
  @elseif($isStaff && ! $mayReveal)
    <p class="ticket-vault-permission-note">Only the assigned support agent or an authorized administrator can reveal shared credentials.</p>
  @endif

  <div class="ticket-secret-revealed" data-revealed-vault hidden>
    <div class="ticket-reveal-heading">
      <div>
        <div class="ticket-vault-eyebrow"><x-icon name="eye" size="15"/> AUTHORIZED REVEAL</div>
        <h3 data-reveal-title>Temporary access details</h3>
        <p>Automatically hidden in <strong data-reveal-countdown>30s</strong>. Passwords and tokens stay masked until you choose Show.</p>
      </div>
      <button type="button" data-hide-revealed class="btn subtle compact">Hide now</button>
    </div>
    <div class="ticket-secret-view-grid">
      @foreach(['username'=>'Username','password'=>'Password','key'=>'API key / token','url'=>'Portal URL'] as $field=>$label)
        <div class="ticket-secret-view-row" data-secret-row="{{ $field }}" hidden>
          <label for="ticket-secret-revealed-{{ $field }}">{{ $label }}</label>
          <div class="ticket-secret-value-control">
            <input id="ticket-secret-revealed-{{ $field }}"
              type="{{ in_array($field, ['password','key'], true) ? 'password' : 'text' }}"
              data-secret-value="{{ $field }}" readonly autocomplete="off" spellcheck="false">
            @if(in_array($field, ['password','key'], true))
              <button type="button" class="ticket-secret-visibility" data-secret-visibility="{{ $field }}"
                aria-label="Show {{ $label }}" aria-pressed="false">Show</button>
            @endif
            <button class="btn subtle compact ticket-secret-copy" type="button" data-secret-copy="{{ $field }}"
              aria-label="Copy {{ $label }}"><x-icon name="copy" size="16"/> <span data-copy-label>Copy</span></button>
          </div>
        </div>
      @endforeach
    </div>
    <p class="ticket-vault-warning"><x-icon name="shield-check" size="17"/> Every reveal is logged. Do not share these values in messages or screenshots. Clear copied credentials from your clipboard after use.</p>
  </div>
  <p class="ticket-secret-error ticket-field-error" data-secret-error hidden role="alert"></p>
</section>

@if($isOwner && $ticket->status !== 'closed')
  <section class="panel support-ticket-panel ticket-secure-share-section" id="ticket-secret-share-section" aria-labelledby="ticket-secret-share-heading">
    <div class="ticket-vault-header">
      <div class="ticket-vault-header-main">
        <span class="ticket-vault-icon" aria-hidden="true"><x-icon name="lock-keyhole" size="21"/></span>
        <div>
          <div class="ticket-vault-eyebrow">ACCOUNT OWNER · SECURE SHARING</div>
          <h2 id="ticket-secret-share-heading">Temporary access for support</h2>
          <p>Create a least-privilege support account or scoped temporary token for troubleshooting.</p>
        </div>
      </div>
      <button type="button" class="btn subtle compact ticket-vault-open" data-open-secure-fields>
        <x-icon name="plus" size="16"/> Enter access details
      </button>
    </div>
    <form method="post" action="{{ route('support.credentials.store', $ticket->public_id) }}" autocomplete="off">
      @csrf
      @include('support.credential-fields')
      <div class="support-form-actions ticket-vault-form-actions" data-secure-submit
        @unless($errors->hasAny(['credential_label','credential_username','credential_password','credential_key','credential_url','credential_days'])) hidden @endunless>
        <button type="submit" class="btn primary"><x-icon name="shield-check" size="16"/> Encrypt &amp; share access</button>
      </div>
    </form>
  </section>
@endif
