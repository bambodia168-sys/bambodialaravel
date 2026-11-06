@extends('layouts.main')
@section('title','My Contracts')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Private workspace · Freelance agreements</div>
    <h1>My Contracts</h1>
    <p>Review account-acknowledged terms, milestones, deliverables and status changes. Agreements are private to their two participants.</p>
  </div>
  <section class="panel">
    <div class="panel-head"><h2>Agreement records <span class="count">{{ $contracts->total() }}</span></h2>
      <div class="toolbar-actions">
        <form method="get" class="table-search" aria-label="Filter private agreements">
          <select name="status" class="table-select" aria-label="Agreement status">
            @foreach(['all'=>'All agreements','draft'=>'Draft','offered'=>'Offered','active'=>'Active','disputed'=>'Disputed','completed'=>'Completed','declined'=>'Declined','cancelled'=>'Cancelled'] as $value=>$label)
              <option value="{{ $value }}" @selected($status===$value)>{{ $label }}</option>
            @endforeach
          </select>
          <button type="submit" class="btn subtle compact">Filter</button>
        </form>
        @if($status!=='all')<a class="btn subtle compact" href="{{ route('contracts.index') }}">Clear</a>@endif
        @if(in_array(auth()->user()->role,['customer','subscriber','super_admin'],true))
          <a class="btn subtle compact" href="{{ route('jobs.mine') }}">My posted jobs</a>
        @endif
      </div>
    </div>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Contract ID</th><th>Title</th><th>Client</th><th>Freelancer</th><th>Estimated USD</th><th>State</th><th>Action</th></tr></thead>
      <tbody>
      @forelse($contracts as $contract)
        <tr>
          <td class="mono">{{ $contract->public_id }}</td>
          <td><strong>{{ $contract->title }}</strong></td>
          <td>{{ $contract->client?->name }}</td><td>{{ $contract->freelancer?->name }}</td>
          <td>{{ number_format((float)$contract->agreed_budget,2) }}</td>
          <td><span class="role-pill">{{ ucfirst($contract->status) }}</span></td>
          <td><a class="btn subtle compact" href="{{ route('contracts.show',$contract) }}">Open contract</a></td>
        </tr>
      @empty
        <tr><td colspan="7" class="empty-cell">No agreements recorded. A client can create a contract draft from a shortlisted job proposal.</td></tr>
      @endforelse
      </tbody>
    </table></div>
    <x-pagination :paginator="$contracts"/>
  </section>
  <p class="hint">Account actions and digests provide an audit trail, not independently verified legal signatures, identity verification, or financial settlement.</p>
</div>
@endsection
