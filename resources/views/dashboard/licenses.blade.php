@extends('layouts.dashboard')
@section('title','License Manager')
@section('content')
<div class="page-intro"><div class="eyebrow">Marketplace Governance</div><h1>License Manager</h1><p>Create explicit license terms for digital products. Activated licenses used by products are immutable; issue a new version to change rights.</p></div>
<section class="panel">
  <div class="panel-head">
    <h2>License definitions <span class="count">{{ $licenses->total() }}</span></h2>
    <div class="toolbar-actions">
      <form method="get" class="table-search"><x-icon name="search" size="17"/><input name="search" value="{{ $search }}" aria-label="Search licenses" placeholder="Code or license name"><button class="btn subtle" type="submit">Search</button></form>
      <button class="btn primary" type="button" data-modal-open="license-create">New license</button>
    </div>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Public ID</th><th>License</th><th>Code</th><th>Version</th><th>Status</th><th>Products</th><th>Action</th></tr></thead>
    <tbody>
      @forelse($licenses as $license)
        <tr>
          <td class="mono">{{ $license->public_id }}</td><td><strong>{{ $license->name }}</strong></td>
          <td class="mono">{{ $license->code }}</td><td>{{ $license->version }}</td>
          <td><span class="role-pill">{{ ucfirst($license->status) }}</span></td><td>{{ $license->listings_count }}</td>
          <td>
            <button class="btn subtle compact" type="button" data-modal-open="license-{{ $license->id }}"><x-icon name="settings" size="16"/> Configure</button>
            <dialog class="config-dialog wide-dialog" id="license-{{ $license->id }}">
              <form method="post" action="{{ route('dashboard.licenses.update',$license) }}">
                @csrf @method('PATCH')
                <h2>{{ $license->name }}</h2>
                <p>Public license ID {{ $license->public_id }} · {{ $license->listings_count }} referenced products</p>
                @if($license->listings_count > 0)
                  <div class="notice">This license is referenced by products. Its terms are locked to prevent silent license changes. Create a new version for updated rights.</div>
                  @if($license->status === 'active')<a href="{{ $license->publicUrl() }}" class="btn subtle" target="_blank" rel="noopener noreferrer">Read published license</a>@endif
                  <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Close</button></div>
                @else
                  <label for="license-code-{{ $license->id }}">License code (A–Z, 0–9, underscore)</label><input id="license-code-{{ $license->id }}" name="code" value="{{ $license->code }}" required maxlength="32">
                  <label for="license-name-{{ $license->id }}">Display name</label><input id="license-name-{{ $license->id }}" name="name" value="{{ $license->name }}" required maxlength="120">
                  <label for="license-version-{{ $license->id }}">Version</label><input id="license-version-{{ $license->id }}" name="version" value="{{ $license->version }}" required placeholder="1.0.0">
                  <label for="license-permissions-{{ $license->id }}">Permissions granted</label><textarea id="license-permissions-{{ $license->id }}" name="permissions" minlength="30" rows="6" required>{{ $license->permissions }}</textarea>
                  <label for="license-restrictions-{{ $license->id }}">Restrictions and prohibited uses</label><textarea id="license-restrictions-{{ $license->id }}" name="restrictions" minlength="30" rows="6" required>{{ $license->restrictions }}</textarea>
                  <label for="license-status-{{ $license->id }}">Status</label><select id="license-status-{{ $license->id }}" name="status"><option value="draft" @selected($license->status==='draft')>Draft</option><option value="active" @selected($license->status==='active')>Active</option></select>
                  <label class="check-label"><input type="checkbox" name="confirm_review" value="1"> I have reviewed these license terms and authorize their publication</label>
                  <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Save license</button></div>
                @endif
              </form>
            </dialog>
          </td>
        </tr>
      @empty<tr><td class="empty-cell" colspan="7">No license definitions have been created.</td></tr>@endforelse
    </tbody>
  </table></div>
  <x-pagination :paginator="$licenses"/>
</section>
<dialog class="config-dialog wide-dialog" id="license-create">
  <form method="post" action="{{ route('dashboard.licenses.store') }}">
    @csrf
    <h2>Create a license definition</h2>
    <p>Define the rights granted to buyers and the uses that are prohibited. Legal review is required before activation.</p>
    <label for="license-new-code">License code</label><input id="license-new-code" name="code" placeholder="REGULAR" maxlength="32" required>
    <label for="license-new-name">License name</label><input id="license-new-name" name="name" maxlength="120" required>
    <label for="license-new-version">Version</label><input id="license-new-version" name="version" value="1.0.0" required>
    <label for="license-new-permissions">Permissions granted</label><textarea id="license-new-permissions" name="permissions" minlength="30" rows="6" required></textarea>
    <label for="license-new-restrictions">Restrictions and prohibited uses</label><textarea id="license-new-restrictions" name="restrictions" minlength="30" rows="6" required></textarea>
    <label for="license-new-status">Publication status</label><select id="license-new-status" name="status"><option value="draft">Draft</option><option value="active">Active</option></select>
    <label class="check-label"><input type="checkbox" name="confirm_review" value="1"> I have reviewed these license terms and authorize their publication</label>
    <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button class="btn primary" type="submit">Create license</button></div>
  </form>
</dialog>

<section class="panel" id="issued-product-licenses">
  <div class="panel-head">
    <div>
      <h2>Issued product licenses <span class="count">{{ $issued->total() }}</span></h2>
      <p>Keys are bound to real downloadable products and their approved file entitlements. License definitions alone never generate customer keys.</p>
    </div>
  </div>
  <div class="notice">Automatic paid-purchase issuance is disabled until verified payment events and order fulfillment are integrated. Existing manual entitlements do not automatically become purchases. Revocation is permanent; it preserves audit history rather than deleting records.</div>
  @if(auth()->user()->role === 'super_admin')
    <details class="license-workflow-panel">
      <summary><x-icon name="plus" size="17"/> Issue a complimentary license from an approved download entitlement</summary>
      <form method="post" action="{{ route('dashboard.licenses.issue') }}" class="license-workflow-form">
        @csrf
        <label for="issued-entitlement">Approved product / release / recipient</label>
        <select id="issued-entitlement" name="product_entitlement_id" required>
          <option value="">Choose an active entitlement with an approved downloadable original</option>
          @foreach($issuable as $grant)
            <option value="{{ $grant->id }}">
              {{ $grant->version?->product?->title }} · {{ $grant->version?->version }}
              · Customer {{ $grant->recipient?->public_id }} · Entitlement #{{ $grant->id }}
            </option>
          @endforeach
        </select>
        <p class="hint">All file, malware, license-definition, release and SHA-256 integrity checks are repeated on the server before issuance.</p>
        <label for="issued-tier">License tier</label>
        <select id="issued-tier" name="tier" required><option value="regular">Regular License</option><option value="extended">Extended License (if offered by product)</option></select>
        <label for="issued-reason">Reason and authorization</label>
        <textarea id="issued-reason" name="reason" rows="3" minlength="15" maxlength="500" required placeholder="Describe why this customer is receiving a complimentary license."></textarea>
        <label class="check-label"><input type="checkbox" name="confirm_complimentary" value="1" required>
          Confirm this is a manual complimentary issuance linked to an approved product and entitlement. No payment has been verified or recorded.</label>
        <button type="submit" class="btn primary"><x-icon name="key-round" size="17"/> Issue license</button>
      </form>
    </details>
  @endif
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>License ID / Key</th><th>Downloadable product</th><th>Customer</th><th>Tier / Terms</th><th>Status</th><th>Active domain</th><th>Management</th></tr></thead>
    <tbody>
    @forelse($issued as $item)
      <tr>
        <td><strong class="mono">{{ $item->public_id }}</strong><div class="text-muted mono">{{ $item->maskedKey() }}</div></td>
        <td><strong>{{ $item->product?->title ?? 'Unavailable product' }}</strong>
          <div class="text-muted">Product {{ $item->product?->public_id }} · File v{{ $item->version?->version }}</div></td>
        <td class="mono">{{ $item->customer?->public_id }}</td>
        <td>{{ ucfirst($item->tier) }}<div class="text-muted">{{ $item->definition?->name }} v{{ $item->definition?->version }}</div></td>
        <td><span class="role-pill">{{ ucfirst($item->status) }}</span>
          @unless($item->isActive())<small class="text-muted">Not currently valid</small>@endunless</td>
        <td>{{ $item->activations->where('status','active')->pluck('domain')->implode(', ') ?: 'Not activated' }}</td>
        <td>
          <button class="btn subtle compact" type="button" data-modal-open="issued-{{ $item->id }}"><x-icon name="settings" size="16"/> Manage</button>
          <dialog class="config-dialog wide-dialog" id="issued-{{ $item->id }}">
            <h2>Manage issued product license</h2>
            <p>License {{ $item->public_id }} · {{ $item->product?->title }} · {{ ucfirst($item->tier) }} · {{ ucfirst($item->status) }}</p>
            <p class="hint">The full secret key is available only to its authenticated customer after password confirmation. Domains and administrative changes are recorded below.</p>
            <div class="license-admin-actions">
              @if($item->status !== 'revoked')
                <form method="post" action="{{ route('dashboard.licenses.issued.status',$item) }}">
                  @csrf @method('PATCH')
                  <label for="status-action-{{ $item->id }}">License status</label>
                  <select id="status-action-{{ $item->id }}" name="action" required>
                    @if($item->status==='active')<option value="suspend">Deactivate / suspend</option>@endif
                    @if($item->status==='suspended')<option value="activate">Reactivate</option>@endif
                    <option value="revoke">Revoke permanently (not deletable)</option>
                  </select>
                  <label>Reason <textarea name="reason" rows="2" minlength="12" maxlength="500" required></textarea></label>
                  <label class="check-label"><input type="checkbox" name="confirm_change" value="1" required> Confirm this administrative action</label>
                  <button class="btn primary compact" type="submit">Update status</button>
                </form>
                @if($item->tier==='regular' && $item->product?->extended_price !== null)
                  <form method="post" action="{{ route('dashboard.licenses.issued.upgrade',$item) }}">
                    @csrf @method('PATCH')
                    <label>Upgrade reason <textarea name="reason" rows="2" minlength="12" maxlength="500" required placeholder="Administrative entitlement to Extended License"></textarea></label>
                    <label class="check-label"><input type="checkbox" name="confirm_manual_upgrade" value="1" required> Approve manual upgrade to reviewed Extended License (no payment)</label>
                    <button class="btn subtle compact" type="submit">Upgrade to Extended</button>
                  </form>
                @endif
                <form method="post" action="{{ route('dashboard.licenses.issued.domain',$item) }}">
                  @csrf @method('PATCH')
                  <label>Replacement domain (optional; leave blank to deactivate all)</label>
                  <input name="domain" placeholder="new.example.com" maxlength="253">
                  <label>Reason <textarea name="reason" rows="2" minlength="12" maxlength="500" required></textarea></label>
                  <button class="btn subtle compact" type="submit">Release current domain</button>
                  <p class="hint">The replacement domain is not auto-activated. The installed script must authenticate with the license key.</p>
                </form>
                <form method="post" action="{{ route('dashboard.licenses.issued.rotate',$item) }}">
                  @csrf
                  <label>Key rotation reason <textarea name="reason" rows="2" minlength="12" maxlength="500" required></textarea></label>
                  <label class="check-label"><input type="checkbox" name="confirm_rotate" value="1" required> Invalidate the old license key permanently</label>
                  <button class="btn subtle compact" type="submit">Rotate key</button>
                </form>
              @else
                <p class="hint">This key has been permanently revoked. Its event history is retained for accountability.</p>
              @endif
            </div>
            <h3>Domain activation & action history</h3>
            <div class="license-history">
              @forelse($item->events->take(30) as $event)
                <div><strong>{{ ucfirst(str_replace('.',' ',$event->action)) }}</strong>
                <span>{{ $event->previous_domain ? $event->previous_domain.' → ' : '' }}{{ $event->domain }}
                  · {{ $event->created_at->format('d M Y H:i') }}
                  · {{ $event->actor?->name ?? 'Installer API' }}
                  @if($event->reason) · {{ $event->reason }} @endif</span></div>
              @empty <p class="hint">No events recorded.</p> @endforelse
            </div>
            <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Close</button></div>
          </dialog>
        </td>
      </tr>
    @empty<tr><td colspan="7" class="empty-cell">No product licenses have been issued. A real approved product and entitlement are required first.</td></tr>@endforelse
    </tbody>
  </table></div>
  <x-pagination :paginator="$issued"/>
</section>
@endsection
