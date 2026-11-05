@extends('layouts.dashboard')
@section('title','Notification Analytics')
@section('content')
<div class="page-intro"><div class="eyebrow">System Communications</div><h1>Notification Analytics</h1><p>Delivery and read-state counts are computed directly from real database records; no external email or push service is implied.</p></div>
<div class="metrics">
<div class="metric"><span><x-icon name="mail"/> Total recorded</span><strong>{{ number_format($total) }}</strong></div>
<div class="metric"><span><x-icon name="alert"/> Unread</span><strong>{{ number_format($unread) }}</strong></div>
<div class="metric"><span><x-icon name="activity"/> Created in last 30 days</span><strong>{{ number_format($last30Days) }}</strong></div>
</div>
<section class="panel"><div class="panel-head"><h2>Notification events <span class="count">{{ $byEvent->count() }}</span></h2></div>
<div class="table-scroll"><table class="data-table"><thead><tr><th>Event</th><th>Total records</th></tr></thead><tbody>
@forelse($byEvent as $event)<tr><td>{{ str_replace('.',' / ',$event->event) }}</td><td>{{ number_format($event->total) }}</td></tr>
@empty<tr><td colspan="2" class="empty-cell">No notifications have been generated.</td></tr>@endforelse
</tbody></table></div></section>
<section class="panel"><div class="panel-head"><h2>Latest activity <span class="count">{{ $notifications->total() }}</span></h2></div>
<div class="table-scroll"><table class="data-table"><thead><tr><th>User</th><th>Event</th><th>Title</th><th>State</th><th>Created</th></tr></thead><tbody>
@forelse($notifications as $note)<tr>
<td>{{ $note->recipient?->name }} <span class="mono">{{ $note->recipient?->public_id }}</span></td>
<td>{{ $note->event }}</td><td>{{ $note->title }}</td><td>{{ $note->read_at?'Read':'Unread' }}</td><td>{{ $note->created_at->format('d M Y H:i') }}</td></tr>
@empty<tr><td colspan="5" class="empty-cell">No in-app notifications recorded.</td></tr>@endforelse
</tbody></table></div><x-pagination :paginator="$notifications"/></section>
@endsection
