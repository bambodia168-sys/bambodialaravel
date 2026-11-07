@extends('layouts.dashboard')
@section('title','Download Entitlements')
@section('content')
<div class="page-intro"><div class="eyebrow">Super Admin · Controlled Fulfillment</div><h1>Download Entitlements</h1>
  <p>Manually grant complimentary or support access to one approved product version. This action does not create an order or accept, process, or verify payment.</p></div>
<section class="panel">
  <div class="panel-head"><h2>Access grants <span class="count">{{ $entitlements->total() }}</span></h2>
    <button class="btn primary" type="button" data-modal-open="grant-entitlement"><x-icon name="key" size="16"/> Grant manual access</button>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Recipient</th><th>Public ID</th><th>Version ID</th><th>Version</th><th>Status</th><th>Expiry</th><th>Action</th></tr></thead>
    <tbody>
      @forelse($entitlements as $entitlement)
        <tr>
          <td><strong>{{ $entitlement->recipient?->name }}</strong><div class="text-muted">{{ $entitlement->recipient?->email }}</div></td>
          <td class="mono">{{ $entitlement->recipient?->public_id }}</td>
          <td class="mono">{{ $entitlement->product_file_version_id }}</td>
          <td>{{ $entitlement->version?->version }}</td>
          <td><span class="role-pill">{{ $entitlement->isActive() ? 'Active' : ($entitlement->status==='revoked' ? 'Revoked' : 'Expired') }}</span></td>
          <td>{{ $entitlement->expires_at?->format('d M Y') ?? 'No expiry' }}</td>
          <td>
            <button type="button" class="btn subtle compact" data-modal-open="entitlement-{{ $entitlement->id }}"><x-icon name="settings" size="15"/> Configure</button>
            <dialog class="config-dialog" id="entitlement-{{ $entitlement->id }}">
              <h2>Download access</h2><p>{{ $entitlement->recipient?->name }} · Version {{ $entitlement->version?->version }}</p>
              <p><strong>Grant reason:</strong> {{ $entitlement->grant_reason }}</p>
              <p><strong>Expires:</strong> {{ $entitlement->expires_at?->format('d M Y H:i') }}</p>
              @if($entitlement->revocation_reason)<p><strong>Revocation:</strong> {{ $entitlement->revocation_reason }}</p>@endif
              <h3>Access history</h3>
              @foreach($entitlement->actions->take(10) as $action)
                <p class="history-entry"><strong>{{ ucfirst($action->action) }}</strong> · {{ $action->created_at->format('d M Y H:i') }} · {{ $action->actor?->name }}<span class="text-muted"> — {{ $action->reason }}</span></p>
              @endforeach
              @if($entitlement->status==='active')
                <form method="post" action="{{ route('dashboard.entitlements.revoke',$entitlement) }}">
                  @csrf @method('PATCH')
                  <label for="revoke-{{ $entitlement->id }}">Reason for revocation</label>
                  <textarea id="revoke-{{ $entitlement->id }}" name="revocation_reason" maxlength="500" minlength="10" rows="3" required></textarea>
                  <div class="dialog-actions"><button class="btn subtle" type="button" data-modal-close>Cancel</button><button class="btn danger" type="submit">Revoke access</button></div>
                </form>
              @else<div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Close</button></div>@endif
            </dialog>
          </td>
        </tr>
      @empty<tr><td class="empty-cell" colspan="7">No product file access has been manually granted.</td></tr>@endforelse
    </tbody>
  </table></div><x-pagination :paginator="$entitlements"/>
</section>
<dialog class="config-dialog wide-dialog" id="grant-entitlement">
  <form method="post" action="{{ route('dashboard.entitlements.grant') }}">
    @csrf
    <h2>Grant complimentary file access</h2>
    <p>Recipients must be existing Customer, Subscriber or Freelancer accounts. Select the exact verified product version to grant.</p>
    <label for="recipient-id">Recipient's eight-digit public ID</label>
    <input id="recipient-id" name="recipient_public_id" pattern="[0-9]{8}" minlength="8" maxlength="8" inputmode="numeric" required>
    <label for="grant-version">Approved product file version</label>
    <select id="grant-version" name="product_file_version_id" required>
      <option value="">Select a reviewed release</option>
      @foreach($available as $version)
        <option value="{{ $version->id }}">{{ $version->product?->title }} · {{ $version->product?->public_id }} · v{{ $version->version }}</option>
      @endforeach
    </select>
    <label for="grant-days">Access duration</label>
    <select id="grant-days" name="expires_days" required>
      <option value="7">7 days</option><option value="30" selected>30 days</option><option value="90">90 days</option><option value="365">365 days</option>
    </select>
    <label for="grant-reason">Reason for complimentary/support access</label>
    <textarea id="grant-reason" name="grant_reason" minlength="12" maxlength="500" rows="4" required></textarea>
    <label class="check-label"><input name="confirm_no_payment" type="checkbox" value="1" required> This is a manual non-payment access grant. I am not marking an order as paid or bypassing payment verification.</label>
    <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button class="btn primary" type="submit">Grant selected version</button></div>
  </form>
</dialog>
@endsection
