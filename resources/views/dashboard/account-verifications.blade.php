@extends('layouts.dashboard')
@section('title','Account Verification')
@section('content')
<div class="container inner-page user-manager-v2">
  <div class="page-intro user-manager-header">
    <div><div class="eyebrow">Trust &amp; Safety / Manual reviews</div><h1>Account Verification</h1>
      <p>Review user accounts manually. Account review is separate from email confirmation, government ID verification and professional badges.</p>
    </div>
    @if(auth()->user()->role==='super_admin')
      <a href="{{ route('dashboard.users') }}" class="btn subtle">User Manager</a>
    @endif
  </div>
  <section class="panel">
    <div class="panel-head user-manager-toolbar">
      <h2>Accounts <span class="count">{{ $users->total() }}</span></h2>
      <form class="user-manager-filters" method="get" action="{{ route('dashboard.account-verifications.index') }}">
        <input name="search" type="search" value="{{ $search }}" placeholder="Search name, email or public ID" maxlength="120" aria-label="Search accounts">
        <select name="status" aria-label="Manual verification status">
          <option value="">All review statuses</option>
          @foreach(['unreviewed'=>'Not reviewed','verified'=>'Verified','revoked'=>'Revoked'] as $value=>$label)
            <option value="{{ $value }}" @selected($status===$value)>{{ $label }}</option>
          @endforeach
        </select>
        <button class="btn subtle compact" type="submit">Search</button>
        <a class="btn subtle compact" href="{{ route('dashboard.account-verifications.index') }}">Reset</a>
      </form>
    </div>
    <div class="table-scroll">
      <table class="data-table user-manager-table">
        <thead><tr><th>Public ID</th><th>User</th><th>Role</th><th>Account</th><th>Manual review</th><th>Email</th><th>Action</th></tr></thead>
        <tbody>
          @forelse($users as $member)
            <tr>
              <td class="mono">{{ $member->public_id }}</td>
              <td><strong>{{ $member->name }}</strong><small class="user-manager-small">{{ $member->email }}</small></td>
              <td>{{ ucwords(str_replace('_',' ',$member->role)) }}</td>
              <td>{{ ucfirst($member->account_status) }}</td>
              <td><span class="user-manager-status user-manager-status--{{ $member->account_review_status }}">{{ match($member->account_review_status){'verified'=>'Verified','revoked'=>'Revoked',default=>'Not reviewed'} }}</span></td>
              <td>{{ $member->email_verified_at?'Confirmed':'Unconfirmed' }}</td>
              <td>
                @if(\App\Support\ManualAccountReview::canReview(auth()->user(),$member))
                  <a class="btn subtle compact" href="{{ route('dashboard.account-verifications.show',$member) }}">Review</a>
                @else
                  <span class="hint">Not eligible</span>
                @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="7" class="empty-cell">No accounts match the selected filters.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <x-pagination :paginator="$users"/>
  </section>
  <p class="hint">Verification is never automatic. Review notes are visible only to authorized administrators and are not published to users or creators.</p>
</div>
@endsection