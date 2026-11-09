@extends('layouts.dashboard')
@section('title','Manual Account Review')
@section('content')
<div class="container inner-page user-manager-v2">
  <div class="page-intro user-manager-header">
    <div><div class="eyebrow">Account Verification / Manual review</div><h1>{{ $member->name }}</h1>
      <p>Public ID <strong class="mono">{{ $member->public_id }}</strong> · {{ ucwords(str_replace('_',' ',$member->role)) }}</p>
    </div>
    <div class="user-manager-heading-actions">
      <a class="btn subtle" href="{{ route('dashboard.account-verifications.index') }}">Back to reviews</a>
      @if(auth()->user()->role==='super_admin')
        <a class="btn subtle" href="{{ route('dashboard.users.show',$member) }}">User details</a>
      @endif
    </div>
  </div>
  <div class="user-manager-two-cols">
    <section class="panel">
      <div class="panel-head"><h2>Account information</h2><span class="user-manager-status user-manager-status--{{ $member->account_status }}">{{ ucfirst($member->account_status) }}</span></div>
      <dl class="user-manager-definition">
        <div><dt>Name</dt><dd>{{ $member->name }}</dd></div>
        <div><dt>Username</dt><dd>{{ $member->username??'Not provided' }}</dd></div>
        <div><dt>Email address</dt><dd>{{ $member->email }}</dd></div>
        <div><dt>Email confirmation</dt><dd>{{ $member->email_verified_at ? 'Confirmed' : 'Not confirmed' }}</dd></div>
        <div><dt>Nationality</dt><dd>{{ $member->userProfile?->nationality??'Not provided' }}</dd></div>
        <div><dt>Date of birth</dt><dd>{{ $member->userProfile?->date_of_birth?->format('d M Y')??'Not provided' }}</dd></div>
        <div><dt>Account created</dt><dd>{{ $member->created_at?->format('d M Y') }}</dd></div>
        <div><dt>Review status</dt><dd><span class="user-manager-status user-manager-status--{{ $member->account_review_status }}">{{ ucfirst($member->account_review_status) }}</span></dd></div>
        @if($member->account_verified_at)
          <div><dt>Verified on</dt><dd>{{ $member->account_verified_at->format('d M Y H:i') }}</dd></div>
        @endif
      </dl>
      <div class="user-manager-note"><x-icon name="shield-check" size="18"/> Manual account review confirms the checks recorded by the reviewer. It does not confirm control of the email address or automatically award Identity Verified, Pro Verified or Skill Certified badges.</div>
    </section>
    <section class="panel">
      <div class="panel-head"><h2>{{ $member->account_review_status==='verified'?'Revoke verification':'Verify account' }}</h2></div>
      <div class="account-review-form-wrap">
      @if($member->account_review_status==='verified')
        <p class="hint">Account review is currently verified. Revocation takes effect immediately and is permanently recorded in the review history.</p>
        <form method="post" action="{{ route('dashboard.account-verifications.revoke',$member) }}" class="user-manager-transfer-form">
          @csrf
          <label>Reason for revocation
            <textarea name="note" maxlength="1000" minlength="12" required rows="4" placeholder="Explain why the previous review is no longer valid. Do not include private ID numbers.">{{ old('note') }}</textarea>
          </label>
          <label>Type REVOKE to confirm
            <input name="confirmation" required pattern="REVOKE" placeholder="REVOKE" autocomplete="off">
          </label>
          <button type="submit" class="btn subtle">Revoke manual verification</button>
        </form>
      @elseif($member->account_status!=='active')
        <div class="user-manager-note">This account is not active. Restore it before approving manual verification.</div>
      @else
        <p class="hint">Review available account information and corroborating evidence before approval. Do not upload or paste identification documents into these notes.</p>
        <form method="post" action="{{ route('dashboard.account-verifications.verify',$member) }}" class="user-manager-transfer-form">
          @csrf
          <label>Verification method
            <select name="method" required>
              @foreach($methods as $code=>$label)
                <option value="{{ $code }}" @selected(old('method')===$code)>{{ $label }}</option>
              @endforeach
            </select>
          </label>
          <label>Internal review note / evidence reference
            <textarea name="note" maxlength="1000" minlength="12" required rows="4" placeholder="Describe the completed review; include internal ticket reference if applicable.">{{ old('note') }}</textarea>
          </label>
          <label>Type VERIFY to confirm
            <input name="confirmation" required pattern="VERIFY" placeholder="VERIFY" autocomplete="off">
          </label>
          <button type="submit" class="btn primary"><x-icon name="shield-check" size="16"/> Verify account</button>
        </form>
      @endif
      </div>
    </section>
  </div>
  <section class="panel">
    <div class="panel-head"><h2>Review history</h2></div>
    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th>Decision</th><th>Reviewer</th><th>Method</th><th>Internal note</th><th>Date</th></tr></thead>
        <tbody>
          @forelse($history as $entry)
            <tr>
              <td>{{ ucfirst($entry->decision) }}</td>
              <td>{{ $entry->reviewer_name??'System / Former administrator' }}</td>
              <td>{{ $entry->method ? ($methods[$entry->method]??ucfirst($entry->method)) : '—' }}</td>
              <td>{{ $entry->note }}</td><td>{{ $entry->created_at }}</td>
            </tr>
          @empty
            <tr><td colspan="5" class="empty-cell">No review decisions recorded yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>
</div>
@endsection