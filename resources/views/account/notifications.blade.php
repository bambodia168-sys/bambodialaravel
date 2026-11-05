@extends('layouts.main')
@section('title','Notification Center')
@section('content')
<div class="container inner-page">
<div class="page-intro"><div class="eyebrow">Account · Activity</div><h1>Notification Center</h1><p>Your private updates from actual Bambodia workflows. You have {{ number_format($unread) }} unread notifications.</p></div>
@if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
<section class="panel"><div class="panel-head"><h2>{{ $filter==='unread'?'Unread':'All' }} notifications <span class="count">{{ $notifications->total() }}</span></h2>
<div class="toolbar-actions">
<a class="btn subtle compact" href="{{ route('account.notifications',['filter'=>'all']) }}">All</a>
<a class="btn subtle compact" href="{{ route('account.notifications',['filter'=>'unread']) }}">Unread</a>
<form method="post" action="{{ route('account.notifications.read-all') }}">@csrf @method('PATCH')<button type="submit" class="btn primary compact" @disabled($unread===0)>Mark all read</button></form>
</div></div>
<div class="table-scroll"><table class="data-table">
<thead><tr><th>Notification</th><th>Event</th><th>State</th><th>Received</th><th>Action</th></tr></thead>
<tbody>
@forelse($notifications as $notification)<tr>
<td><strong>{{ $notification->title }}</strong><div class="text-muted">{{ $notification->message }}</div></td>
<td>{{ str_replace('.',' / ',$notification->event) }}</td>
<td><span class="role-pill">{{ $notification->read_at?'Read':'Unread' }}</span></td>
<td>{{ $notification->created_at->format('d M Y H:i') }}</td>
<td><form method="post" action="{{ route('account.notifications.read',$notification) }}">@csrf @method('PATCH')<button type="submit" class="btn subtle compact">Open</button></form></td></tr>
@empty<tr><td colspan="5" class="empty-cell">No recorded activity for this filter.</td></tr>@endforelse
</tbody></table></div><x-pagination :paginator="$notifications"/></section>
</div>
@endsection
