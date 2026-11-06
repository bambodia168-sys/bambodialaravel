@extends('layouts.workspace')
@section('workspace','account')
@section('title','My Product Licenses')
@section('content')
<div class="container inner-page">
  <div class="page-intro">
    <div class="eyebrow">Account · Digital Product Licensing</div>
    <h1>My Licenses</h1>
    <p>View your product licenses, registered domains and activation history, or manage an installation.</p>
  </div>

  <section class="panel license-account-panel" aria-labelledby="account-licenses-title">
    <div class="panel-head">
      <div>
        <h2 id="account-licenses-title">Product licenses <span class="count">{{ $licenses->total() }}</span></h2>
        <p>Licenses associated with your account</p>
      </div>
      <a class="btn subtle compact" href="{{ route('account.downloads') }}"><x-icon name="download" size="16"/> My Downloads</a>
    </div>
    <div class="table-scroll license-account-table-scroll" tabindex="0" role="region" aria-label="My product licenses table">
      <table class="data-table license-account-table">
        <thead>
          <tr>
            <th scope="col">Product</th>
            <th scope="col">License</th>
            <th scope="col">Status</th>
            <th scope="col">Active domain</th>
            <th scope="col">Expires</th>
            <th scope="col">Action</th>
          </tr>
        </thead>
        <tbody>
          @forelse($licenses as $license)
            @php
              $activeDomains = $license->activations->where('status','active')->pluck('domain');
              $displayStatus = $license->status === 'active' && ! $license->isActive()
                ? 'Unavailable' : ucfirst($license->status);
            @endphp
            <tr>
              <td>
                <strong class="license-table-primary">{{ $license->product?->title ?? 'Unavailable product' }}</strong>
                <span class="license-table-secondary">Product {{ $license->product?->public_id }} · Release v{{ $license->version?->version }}</span>
              </td>
              <td>
                <strong class="license-table-primary">{{ ucfirst($license->tier) }} License</strong>
                <span class="license-table-secondary mono">{{ $license->maskedKey() }}</span>
              </td>
              <td><span class="role-pill">{{ $displayStatus }}</span></td>
              <td>
                @if($activeDomains->isNotEmpty())
                  <span class="license-table-domain">{{ $activeDomains->implode(', ') }}</span>
                @elseif($license->pending_domain)
                  <span class="license-table-secondary">Awaiting {{ $license->pending_domain }}</span>
                @else
                  <span class="text-muted">Not activated</span>
                @endif
              </td>
              <td>{{ $license->expires_at?->format('d M Y') ?? 'No expiry' }}</td>
              <td>
                <button type="button" class="btn subtle compact" data-modal-open="account-license-{{ $license->id }}" aria-haspopup="dialog" aria-controls="account-license-{{ $license->id }}">
                  <x-icon name="settings" size="16"/> {{ $license->status === 'revoked' ? 'Details' : 'Manage' }}
                </button>
                <dialog class="config-dialog wide-dialog license-account-dialog" id="account-license-{{ $license->id }}" aria-labelledby="account-license-title-{{ $license->id }}">
                  <div class="license-account-dialog-heading">
                    <div>
                      <h2 id="account-license-title-{{ $license->id }}">{{ $license->product?->title ?? 'Product license' }}</h2>
                      <p>{{ ucfirst($license->tier) }} License · {{ $displayStatus }}</p>
                    </div>
                    <button type="button" class="btn subtle compact" data-modal-close aria-label="Close license details"><x-icon name="x" size="17"/></button>
                  </div>
                  <div class="license-key-summary">
                    <div><small>License ID</small><strong class="mono">{{ $license->public_id }}</strong></div>
                    <div><small>License key</small><strong class="mono">{{ $license->maskedKey() }}</strong></div>
                    <div><small>License source</small><strong>{{ $license->source === 'admin_complimentary' ? 'Complimentary grant' : ($license->source === 'verified_purchase' ? 'Verified purchase' : 'Pending review') }}</strong></div>
                    <div><small>Expires</small><strong>{{ $license->expires_at?->format('d M Y') ?? 'No fixed expiration' }}</strong></div>
                  </div>
                  @if($license->status !== 'revoked')
                    <details class="license-workflow-panel">
                      <summary><x-icon name="key-round" size="16"/> Reveal installer license key</summary>
                      <form method="post" action="{{ route('account.licenses.reveal',$license) }}" class="license-workflow-form">
                        @csrf
                        <label for="reveal-password-{{ $license->id }}">Confirm your account password</label>
                        <input id="reveal-password-{{ $license->id }}" type="password" name="password" autocomplete="current-password" required>
                        <button class="btn primary compact" type="submit">Reveal license key</button>
                      </form>
                    </details>
                    <details class="license-workflow-panel">
                      <summary><x-icon name="globe" size="16"/> Change installed domain</summary>
                      <p class="hint">Your current domain will be released. Activate the replacement installation with your license key.</p>
                      <form method="post" action="{{ route('account.licenses.domain',$license) }}" class="license-workflow-form">
                        @csrf @method('PATCH')
                        <label for="license-domain-{{ $license->id }}">Replacement domain</label>
                        <input id="license-domain-{{ $license->id }}" name="domain" maxlength="253" placeholder="new.example.com" required>
                        <label for="license-password-{{ $license->id }}">Confirm account password</label>
                        <input id="license-password-{{ $license->id }}" name="password" type="password" autocomplete="current-password" required>
                        <button class="btn subtle compact" type="submit">Transfer domain</button>
                      </form>
                    </details>
                  @endif
                  <div class="license-history">
                    <h3>Domain activations</h3>
                    @forelse($license->activations as $activation)
                      <div>
                        <strong>{{ $activation->domain }}</strong>
                        <span>{{ ucfirst($activation->status) }} · {{ $activation->activated_at?->format('d M Y H:i') }}
                          @if($activation->deactivated_at) · Deactivated {{ $activation->deactivated_at->format('d M Y H:i') }}@endif
                          @if($activation->last_validated_at) · Last verified {{ $activation->last_validated_at->format('d M Y H:i') }}@endif
                        </span>
                      </div>
                    @empty
                      <p class="hint">No domain activations yet.</p>
                    @endforelse
                    <h3>License activity</h3>
                    @forelse($license->events->take(20) as $event)
                      <div>
                        <strong>{{ ucfirst(str_replace('.',' ',$event->action)) }}</strong>
                        <span>{{ $event->previous_domain ? $event->previous_domain.' → ' : '' }}{{ $event->domain }} · {{ $event->created_at->format('d M Y H:i') }}</span>
                      </div>
                    @empty
                      <p class="hint">No activity recorded.</p>
                    @endforelse
                  </div>
                  <div class="dialog-actions">
                    <button type="button" class="btn subtle" data-modal-close>Close</button>
                  </div>
                </dialog>
              </td>
            </tr>
          @empty
            <tr>
              <td class="empty-cell license-table-empty-cell" colspan="6">
                <div class="license-empty-state">
                  <span class="license-empty-state-icon"><x-icon name="key-round" size="24"/></span>
                  <strong>No licenses yet</strong>
                  <p>Your product licenses will appear here when issued to your account.</p>
                  <a class="btn subtle compact" href="{{ route('account.downloads') }}">View My Downloads</a>
                </div>
              </td>
            </tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <x-pagination :paginator="$licenses"/>
  </section>
</div>
@endsection
