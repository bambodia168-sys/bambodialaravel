@extends('layouts.dashboard')
@section('title','User Details')
@section('content')
<div class="container inner-page user-manager-v2">
  <div class="page-intro user-manager-header"><div>
    <div class="eyebrow">User Manager / Account details</div>
    <h1>{{ $member->name }}</h1>
    <p>Public ID <strong class="mono">{{ $member->public_id }}</strong> · {{ $member->username?'@'.$member->username.' · ':'' }}{{ ucwords(str_replace('_',' ',$member->role)) }}</p>
  </div><div class="user-manager-heading-actions">
    <a class="btn subtle" href="{{ route('dashboard.users') }}"><x-icon name="arrow-left" size="16"/> All users</a>
    <a class="btn primary" href="{{ route('dashboard.users.edit',$member) }}"><x-icon name="pencil" size="16"/> Edit account</a>
  </div></div>

  @if(\App\Support\ManualAccountReview::canReview(auth()->user(),$member))
    <section class="panel">
      <div class="panel-head">
        <h2>Manual account verification</h2>
        <a class="btn subtle compact" href="{{ route('dashboard.account-verifications.show',$member) }}">Review account</a>
      </div>
      <div class="user-manager-note"><x-icon name="shield-check" size="18"/> Administrators can approve or revoke account verification after a documented review. Email confirmation and professional credential badges remain separate checks.</div>
    </section>
  @endif
  <div class="user-manager-stats">
    @foreach($counts as $label=>$count)
    <div class="user-manager-stat"><strong>{{ number_format($count) }}</strong><span>{{ ucwords(str_replace('_',' ',$label)) }}</span></div>
    @endforeach
  </div>
  <div class="user-manager-two-cols">
    <section class="panel">
      <div class="panel-head"><h2>Account overview</h2><span class="user-manager-status user-manager-status--{{ $member->account_status }}">{{ ucfirst($member->account_status) }}</span></div>
      <dl class="user-manager-definition">
        <div><dt>Full name</dt><dd>{{ $member->name }}</dd></div>
        <div><dt>Username</dt><dd>{{ $member->username??'Not set' }}</dd></div>
        <div><dt>Email</dt><dd>{{ $member->email }}</dd></div>
        <div><dt>Account review</dt><dd><span class="user-manager-status user-manager-status--{{ $member->account_review_status }}">{{ match($member->account_review_status){'verified'=>'Verified','revoked'=>'Revoked',default=>'Not reviewed'} }}</span></dd></div>
        <div><dt>Email verification</dt><dd>{{ $member->email_verified_at?'Confirmed':'Not confirmed' }}</dd></div>
        <div><dt>Role</dt><dd>{{ ucwords(str_replace('_',' ',$member->role)) }}</dd></div>
        <div><dt>Joined</dt><dd>{{ $member->created_at?->format('d M Y') }}</dd></div>
        <div><dt>Date of birth</dt><dd>{{ $member->userProfile?->date_of_birth?->format('d M Y')??'Not set' }}</dd></div>
        <div><dt>Nationality</dt><dd>{{ $member->userProfile?->nationality??'Not set' }}</dd></div>
      </dl>
      @if($member->userProfile?->bio)
        <div class="user-manager-internal-bio"><strong>Private biography</strong><p>{{ $member->userProfile->bio }}</p></div>
      @endif
    </section>
    <section class="panel">
      <div class="panel-head"><h2>Effective permissions</h2></div>
      <div class="user-manager-permission-list">
        @foreach($permissions as $key=>$permission)
        <div class="user-manager-permission">
          <span><strong>{{ $permission['name'] }}</strong><small>{{ $permission['role_permits']?'Subject to role restrictions':'Not available to role' }}</small></span>
          <span class="user-manager-status user-manager-status--{{ $permission['allowed']?'active':'suspended' }}">{{ $permission['allowed']?'Allowed':'Restricted' }}</span>
        </div>
        @endforeach
      </div>
      <div class="user-manager-note"><x-icon name="shield-check" size="18"/> Privileges beyond these three workflows remain governed by the platform's role-based access system.</div>
    </section>
  </div>

  <section class="panel">
    <div class="panel-head"><h2>Owned marketplace listings <span class="count">{{ $listings->count() }}</span></h2></div>
    <div class="table-scroll">
      <table class="data-table"><thead><tr><th>Reference</th><th>Type</th><th>Title</th><th>Status</th><th>Action</th></tr></thead><tbody>
      @forelse($listings as $item)
        <tr><td class="mono">{{ $item->public_id }}</td><td>{{ ucfirst($item->type) }}</td><td>{{ $item->title }}</td><td>{{ ucfirst($item->status) }}</td>
          <td><a class="btn subtle compact" href="{{ route('dashboard.users.transfer',[$member,'type'=>$item->type,'id'=>$item->id]) }}">Transfer</a></td></tr>
      @empty <tr><td colspan="5" class="empty-cell">No owned listings.</td></tr> @endforelse
      </tbody></table>
    </div>
    @if(array_sum(array_intersect_key($counts,array_flip(['products','projects','services','freelance'])))>50)
      <p class="hint">Showing the latest 50 listings. Older records remain unchanged; search or review them in the marketplace manager.</p>
    @endif
  </section>
  <section class="panel">
    <div class="panel-head"><h2>Owned jobs <span class="count">{{ $counts['jobs'] }}</span></h2></div>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Reference</th><th>Title</th><th>Status</th><th>Updated</th><th>Action</th></tr></thead><tbody>
      @forelse($jobs as $job)
      <tr><td class="mono">{{ $job->public_id }}</td><td>{{ $job->title }}</td><td>{{ ucfirst($job->status) }}</td><td>{{ $job->updated_at?->format('d M Y') }}</td>
        <td><a class="btn subtle compact" href="{{ route('dashboard.users.transfer',[$member,'type'=>'job','id'=>$job->id]) }}">Transfer</a></td></tr>
      @empty <tr><td colspan="5" class="empty-cell">No owned jobs.</td></tr> @endforelse
    </tbody></table></div>
  </section>
  <section class="panel">
    <div class="panel-head"><h2>Ownership transfer history</h2></div>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Resource</th><th>From ID</th><th>To ID</th><th>Reason</th><th>Date</th></tr></thead><tbody>
        @forelse($transfers as $record)
          <tr><td>{{ ucfirst($record->resource_type) }} · {{ $record->resource_public_id }}<small class="user-manager-small">{{ $record->resource_title }}</small></td>
          <td>{{ $record->from_user_id }}</td><td>{{ $record->to_user_id }}</td><td>{{ $record->reason }}</td><td>{{ $record->created_at }}</td></tr>
        @empty <tr><td colspan="5" class="empty-cell">No ownership transfers recorded.</td></tr> @endforelse
      </tbody></table>
    </div>
  </section>
  <section class="panel">
    <div class="panel-head"><h2>Administrative change history</h2></div>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Action</th><th>Fields</th><th>Reason</th><th>At</th></tr></thead><tbody>
      @forelse($events as $event)
      <tr><td>{{ ucfirst($event->action) }}</td><td>{{ implode(', ',json_decode($event->changed_fields??'[]',true)?:[]) }}</td>
      <td>{{ $event->reason??'—' }}</td><td>{{ $event->created_at }}</td></tr>
      @empty <tr><td colspan="4" class="empty-cell">No recorded administrative changes.</td></tr> @endforelse
      </tbody></table></div>
  </section>
  <section class="panel user-manager-danger-zone">
    <div class="panel-head"><h2>Account lifecycle</h2></div>
    <div class="user-manager-danger-content">
      @if($member->account_status!=='active')
        <p>This account is {{ $member->account_status }}. Restore it to allow login and API access again.</p>
        <form method="post" action="{{ route('dashboard.users.restore',$member) }}">@csrf
          <button type="submit" class="btn primary">Restore account</button></form>
      @else
        <p>Archiving is reversible and blocks logins. It does not remove legal records, contracts, published listings, or license history.</p>
        <form method="post" action="{{ route('dashboard.users.archive',$member) }}" class="user-manager-archive-form">
          @csrf @method('DELETE')
          <label>Reason for archiving <input name="reason" minlength="12" maxlength="500" required placeholder="Internal administrative justification"></label>
          <label>Type ARCHIVE to confirm <input name="confirmation" required pattern="ARCHIVE" autocomplete="off" placeholder="ARCHIVE"></label>
          <button class="btn subtle" type="submit"><x-icon name="archive" size="16"/> Archive account</button>
        </form>
      @endif
    </div>
  </section>
</div>
@endsection