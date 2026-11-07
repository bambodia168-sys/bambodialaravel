@extends('layouts.dashboard')
@section('title','Support Center')
@section('content')
<div class="page-intro"><div class="eyebrow">Customer Care</div><h1>Support Center</h1><p>Review and respond to private user support requests.</p><div class="toolbar-actions">
  <a class="btn subtle" href="{{ route('support') }}">Public Help Center</a>
  @if(in_array(auth()->user()->role,['super_admin','admin'],true))<a class="btn primary" href="{{ route('dashboard.knowledge.index') }}">Manage knowledge base</a>@endif
</div></div>
<section class="panel">
  <div class="panel-head"><h2>Tickets <span class="count">{{ $tickets->total() }}</span></h2>
    <form method="get" class="table-search"><x-icon name="filter" size="17"/>
      <input name="q" maxlength="120" aria-label="Search support tickets" placeholder="Subject or ticket ID" value="{{ $search }}"><select name="status" class="table-select" aria-label="Filter ticket status"><option value="">All statuses</option>@foreach(['open'=>'Open','in_progress'=>'In progress','resolved'=>'Resolved'] as $value=>$label)<option value="{{ $value }}" @selected($status===$value)>{{ $label }}</option>@endforeach</select><button type="submit" class="btn subtle">Filter</button></form>
  </div>
  <nav class="workspace-queue-toolbar workspace-queue-tabs" aria-label="Support ticket status">
    @foreach([''=>'All','open'=>'Open','in_progress'=>'In progress','resolved'=>'Resolved'] as $value=>$label)
      <a href="{{ route('dashboard.support',array_merge(request()->except('status','page'),$value===''?[]:['status'=>$value])) }}"
        class="{{ $status===$value?'is-active':'' }}" @if($status===$value)aria-current="page"@endif>
        {{ $label }} <span>{{ $value===''?$counts->sum():(int)$counts->get($value,0) }}</span>
      </a>
    @endforeach
    @if($search!=='')<a href="{{ route('dashboard.support',$status===''?[]:['status'=>$status]) }}">Clear search</a>@endif
  </nav>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Ticket ID</th><th>Subject</th><th>Customer</th><th>Status</th><th>Updated</th><th>Action</th></tr></thead><tbody>
  @forelse($tickets as $ticket)
    <tr><td class="mono">{{ $ticket->public_id }}</td><td><strong>{{ $ticket->subject }}</strong></td><td>{{ $ticket->owner?->name }}</td><td><span class="role-pill">{{ ucwords(str_replace('_',' ',$ticket->status)) }}</span></td><td>{{ $ticket->updated_at->format('d M Y') }}</td><td>
      <a class="btn subtle compact" href="{{ route('dashboard.support.show',$ticket->public_id) }}">Open</a>
      <button class="btn subtle compact" type="button" data-modal-open="ticket-{{ $ticket->id }}">Configure</button>
      <dialog class="config-dialog" id="ticket-{{ $ticket->id }}">
        <form method="post" action="{{ route('dashboard.support.status',$ticket->public_id) }}">@csrf @method('PATCH')
        <h2>Configure ticket</h2><p>Ticket {{ $ticket->public_id }}</p>
        <label for="ticket-status-{{ $ticket->id }}">Status</label><select name="status" id="ticket-status-{{ $ticket->id }}">@foreach(['open'=>'Open','in_progress'=>'In progress','resolved'=>'Resolved'] as $value=>$label)<option value="{{ $value }}" @selected($ticket->status===$value)>{{ $label }}</option>@endforeach</select>
        <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button type="submit" class="btn primary">Save status</button></div>
        </form>
      </dialog>
    </td></tr>
  @empty<tr><td colspan="6" class="empty-cell">No support tickets match this filter.</td></tr>@endforelse
  </tbody></table></div>
  <x-pagination :paginator="$tickets"/>
</section>
@endsection
