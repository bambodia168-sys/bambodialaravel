@extends('layouts.workspace')
@section('workspace','account')
@section('title','Notification Center')
@section('content')
<div class="container inner-page">
<div class="page-intro"><div class="eyebrow">Account · Activity</div><h1>Notification Center</h1><p>Your private updates from actual Bambodia workflows. You have {{ number_format($unread) }} unread notifications.</p></div>
<section class="panel"><div class="panel-head"><h2>{{ ucfirst($filter) }} notifications <span class="count">{{ $notifications->total() }}</span></h2>
<div class="toolbar-actions">
<a class="btn subtle compact" href="{{ route('account.notifications',array_merge(['filter'=>'all'],$event==='all'?[]:['event'=>$event])) }}">All</a>
<a class="btn subtle compact" href="{{ route('account.notifications',array_merge(['filter'=>'unread'],$event==='all'?[]:['event'=>$event])) }}">Unread</a>
<a class="btn subtle compact" href="{{ route('account.notifications',array_merge(['filter'=>'read'],$event==='all'?[]:['event'=>$event])) }}">Read</a>
<form method="post" action="{{ route('account.notifications.read-all') }}">@csrf @method('PATCH')<button type="submit" class="btn primary compact" @disabled($unread===0)>Mark all read</button></form>
</div></div>
<form class="workspace-queue-toolbar" method="get" role="search" aria-label="Filter your notifications">
  <input type="hidden" name="filter" value="{{ $filter }}">
  <label for="account-notification-event">Event type</label>
  <select id="account-notification-event" name="event" class="table-select">
    <option value="all">All event types</option>
    @foreach($events as $eventName=>$total)
      <option value="{{ $eventName }}" @selected($event===$eventName)>{{ str_replace('.',' / ',$eventName) }} ({{ $total }})</option>
    @endforeach
  </select>
  <button type="submit" class="btn subtle compact">Filter</button>
  @if($event!=='all')<a href="{{ route('account.notifications',['filter'=>$filter]) }}" class="btn subtle compact">Clear</a>@endif
</form>
<div class="table-scroll"><table class="data-table">
<thead><tr><th>Notification</th><th>Event</th><th>State</th><th>Received</th><th>Action</th></tr></thead>
<tbody>
@forelse($notifications as $notification)<tr>
<td><strong>{{ $notification->title }}</strong><div class="text-muted">{{ $notification->message }}</div></td>
<td>{{ str_replace('.',' / ',$notification->event) }}</td>
<td><span class="role-pill">{{ $notification->read_at?'Read':'Unread' }}</span></td>
<td>{{ $notification->created_at->format('d M Y H:i') }}</td>
<td><x-row-action-menu :id="'notification-'.$notification->id" :label="$notification->title"><form method="post" action="{{ route('account.notifications.read',$notification) }}">@csrf @method('PATCH')<button type="submit"><x-icon name="eye" size="16"/> Open</button></form></x-row-action-menu></td></tr>
@empty<tr><td colspan="5" class="empty-cell">No recorded activity for this filter.</td></tr>@endforelse
</tbody></table></div><x-pagination :paginator="$notifications"/></section>
</div>
@endsection
