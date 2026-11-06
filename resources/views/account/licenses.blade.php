@extends('layouts.workspace')
@section('workspace','account')
@section('title','My Product Licenses')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Account · Digital Product Licensing</div><h1>My Licenses</h1>
    <p>Manage keys linked to downloadable products you are entitled to use. View activated domains, transfer an installation and check recorded history.</p></div>
  <div class="notice">Only approved downloadable products can have issued licenses. Complimentary administrative licenses are labelled as such; a manual entitlement is not proof of purchase. Automatic purchase issuance awaits verified checkout integration.</div>
  <div class="license-cards">
    @forelse($licenses as $license)
      <section class="panel license-customer-card">
        <div class="panel-head"><div><h2>{{ $license->product?->title ?? 'Unavailable product' }}</h2>
          <p>Product {{ $license->product?->public_id }} · File v{{ $license->version?->version }} · {{ ucfirst($license->tier) }} License</p></div>
          <span class="role-pill">{{ ucfirst($license->status) }}</span></div>
        <div class="license-key-summary">
          <div><small>License ID</small><strong class="mono">{{ $license->public_id }}</strong></div>
          <div><small>Key</small><strong class="mono">{{ $license->maskedKey() }}</strong></div>
          <div><small>Issued as</small><strong>{{ $license->source === 'admin_complimentary' ? 'Complimentary administrative grant' : 'Verified purchase' }}</strong></div>
          <div><small>Expires</small><strong>{{ $license->expires_at?->format('d M Y') ?? 'No fixed expiration' }}</strong></div>
        </div>
        <p class="hint">License is {{ $license->isActive() ? 'currently active' : 'not currently valid' }}. A valid key also requires the approved product release and its active download entitlement.</p>
        @if($license->status !== 'revoked')
          <details class="license-workflow-panel">
            <summary><x-icon name="key-round" size="16"/> Reveal key for your script installer</summary>
            <form method="post" action="{{ route('account.licenses.reveal',$license) }}" class="license-workflow-form">
              @csrf
              <label for="reveal-password-{{ $license->id }}">Confirm your account password</label>
              <input id="reveal-password-{{ $license->id }}" type="password" name="password" autocomplete="current-password" required>
              <button class="btn primary compact" type="submit">Reveal license key securely</button>
            </form>
          </details>
          <details class="license-workflow-panel">
            <summary><x-icon name="globe" size="16"/> Change installed domain</summary>
            <p class="hint">Transferring releases the current domain. The new installation must then activate online with your license key.</p>
            <form method="post" action="{{ route('account.licenses.domain',$license) }}" class="license-workflow-form">
              @csrf @method('PATCH')
              <label for="license-domain-{{ $license->id }}">Replacement domain</label>
              <input id="license-domain-{{ $license->id }}" name="domain" maxlength="253" placeholder="my-new-domain.com" required>
              <label for="license-password-{{ $license->id }}">Confirm account password</label>
              <input id="license-password-{{ $license->id }}" name="password" type="password" autocomplete="current-password" required>
              <button class="btn subtle compact" type="submit">Deactivate previous domain</button>
            </form>
          </details>
        @endif
        <div class="license-history">
          <h3>Activated domains</h3>
          @forelse($license->activations as $activation)
            <div><strong>{{ $activation->domain }}</strong><span>{{ ucfirst($activation->status) }}
            · Activated {{ $activation->activated_at?->format('d M Y H:i') }}
            @if($activation->deactivated_at) · Deactivated {{ $activation->deactivated_at->format('d M Y H:i') }}@endif
            @if($activation->last_validated_at) · Last verified {{ $activation->last_validated_at->format('d M Y H:i') }}@endif
            </span></div>
          @empty<p class="hint">No activated domains yet.</p>@endforelse
          <h3>License activity</h3>
          @forelse($license->events->take(20) as $event)
            <div><strong>{{ ucfirst(str_replace('.',' ',$event->action)) }}</strong>
              <span>{{ $event->previous_domain ? $event->previous_domain.' → ' : '' }}{{ $event->domain }} · {{ $event->created_at->format('d M Y H:i') }}</span></div>
          @empty<p class="hint">No history yet.</p>@endforelse
        </div>
      </section>
    @empty
      <section class="panel"><p>No product license keys have been issued to your account. Uploads, unpaid orders and license definitions alone do not generate a key.</p></section>
    @endforelse
  </div>
  <x-pagination :paginator="$licenses"/>
</div>
@endsection
