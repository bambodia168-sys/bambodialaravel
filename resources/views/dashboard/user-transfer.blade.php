@extends('layouts.dashboard')
@section('title','Transfer Ownership')
@section('content')
<div class="container inner-page user-manager-v2">
  <div class="page-intro user-manager-header"><div>
    <div class="eyebrow">User Manager / Ownership</div><h1>Transfer {{ ucfirst($type) }}</h1>
    <p>Move an eligible marketplace record to another active user without changing its public reference.</p>
  </div><a class="btn subtle" href="{{ route('dashboard.users.show',$member) }}"><x-icon name="arrow-left" size="16"/> Back to user</a></div>

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
      <div class="user-manager-note"><x-icon name="shield-alert" size="18"/> Items with media, private product versions, issued licenses, proposals or private inquiries require a separate rights/contract review. Unsafe transfers are blocked rather than partly applied.</div>
    </section>
    <section class="panel">
      <div class="panel-head"><h2>New owner</h2></div>
      <form method="post" action="{{ route('dashboard.users.transfer.store',$member) }}" class="user-manager-transfer-form">
        @csrf
        <input type="hidden" name="type" value="{{ $type }}">
        <input type="hidden" name="id" value="{{ $item->id }}">
        <label>Recipient's exact public ID or email
          <input name="recipient" value="{{ old('recipient') }}" maxlength="255" required placeholder="8-digit ID or account email">
          <small>{{ $type==='job'?'Eligible: customer, subscriber or super administrator.':'Eligible: freelancer or super administrator.' }} The recipient must be active.</small>
        </label>
        <label>Reason / evidence
          <textarea name="reason" rows="4" minlength="20" maxlength="500" required placeholder="Why this transfer is authorized">{{ old('reason') }}</textarea>
        </label>
        <label class="user-manager-inline-check"><input name="rights_confirmed" type="checkbox" value="1" required> I confirm this administrative reassignment is authorized and does not transfer unrelated contracts, copyright or purchases.</label>
        <label>Type TRANSFER to confirm <input name="confirmation" required pattern="TRANSFER" placeholder="TRANSFER" autocomplete="off"></label>
        <button type="submit" class="btn primary"><x-icon name="arrow-left-right" size="16"/> Transfer ownership</button>
      </form>
    </section>
  </div>
</div>
@endsection