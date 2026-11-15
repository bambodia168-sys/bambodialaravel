@extends('layouts.dashboard')
@section('title','Transfer Ownership')
@section('content')
<div class="container inner-page user-manager-v2">
  <div class="page-intro user-manager-header"><div>
    <div class="eyebrow">User Manager / Ownership</div><h1>Transfer {{ ucfirst($type) }}</h1>
    <p>Move an eligible marketplace record to another active user without changing its public reference.</p>
  </div><a class="btn subtle" href="{{ $fromManager ? \App\Support\MarketplaceManagement::indexUrl($type) : route('dashboard.users.show', $member) }}"><x-icon name="arrow-left" size="16"/> {{ $fromManager ? 'Back to manager' : 'Back to user' }}</a></div>

  <div class="user-manager-two-cols">
    <section class="panel">
      <div class="panel-head"><h2>Current resource</h2></div>
      <dl class="user-manager-definition">
        <div><dt>Type</dt><dd>{{ ucfirst($type) }}</dd></div>
        <div><dt>Reference</dt><dd class="mono">{{ $item->public_id }}</dd></div>
        <div><dt>Title</dt><dd>{{ $item->title }}</dd></div>
        <div><dt>Status</dt><dd>{{ ucfirst($item->status) }}</dd></div>
        <div><dt>Current owner</dt><dd>{{ $member->name }} ({{ $member->public_id }})</dd></div>
      </dl>
      <div class="user-manager-note"><x-icon name="shield-alert" size="18"/> Media owned by the current publisher and used only on this record can move with it. Customer activity, private product files, licenses and shared media may require review.</div>
    </section>
    <section class="panel">
      <div class="panel-head"><h2>New owner</h2></div>
      <form method="post" action="{{ route($fromManager ? 'dashboard.marketplace.transfer.store' : 'dashboard.users.transfer.store', $member) }}" class="user-manager-transfer-form">
        @csrf
        <input type="hidden" name="type" value="{{ $type }}">
        <input type="hidden" name="id" value="{{ $item->id }}">
        @error('transfer')
          <div class="user-manager-note" role="alert" style="margin:0"><x-icon name="shield-alert" size="18"/> {{ $message }}</div>
        @enderror
        <label>Recipient's exact public ID or email
          <input name="recipient" value="{{ old('recipient') }}" maxlength="255" required placeholder="8-digit ID or account email"
            aria-describedby="transfer-recipient-hint @error('recipient') transfer-recipient-error @enderror" @error('recipient') aria-invalid="true" @enderror>
          <small id="transfer-recipient-hint">{{ $type==='job'?'Eligible: customer, subscriber or super administrator.':'Eligible: freelancer or super administrator.' }} The recipient must be active.</small>
          @error('recipient')<small id="transfer-recipient-error" role="alert">{{ $message }}</small>@enderror
        </label>
        <label>Reason / evidence
          <textarea name="reason" rows="4" minlength="20" maxlength="500" required placeholder="Why this transfer is authorized">{{ old('reason') }}</textarea>
        </label>
        <label class="user-manager-inline-check"><input name="rights_confirmed" type="checkbox" value="1" required> I confirm the reassignment and any attached owned-media access transfer are authorized. Existing copyright claims, unrelated contracts and purchases are not transferred.</label>
        <label>Type TRANSFER to confirm <input name="confirmation" required pattern="TRANSFER" placeholder="TRANSFER" autocomplete="off"></label>
        <button type="submit" class="btn primary"><x-icon name="arrow-left-right" size="16"/> Transfer ownership</button>
      </form>
    </section>
  </div>
</div>
@endsection
