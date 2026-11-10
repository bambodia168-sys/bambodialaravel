@extends('layouts.dashboard')
@section('title','Security Dashboard')
@section('content')
<div class="page-intro"><div class="eyebrow">Access & Monitoring</div><h1>Security Dashboard</h1><p>Operational security activity recorded by Bambodia, not simulated analytics. Counts below reflect stored events.</p></div>
<div class="metrics">
  <div class="metric"><span><x-icon name="activity"/> Events in past 24 hours</span><strong>{{ number_format($eventsToday) }}</strong></div>
  <div class="metric"><span><x-icon name="shield"/> Failed logins in past 24 hours</span><strong>{{ number_format($failedLoginsToday) }}</strong></div>
  <div class="metric"><span><x-icon name="key"/> Unexpired active tokens</span><strong>{{ number_format($activeTokens) }}</strong></div>
  <div class="metric"><span><x-icon name="alert-triangle"/> Alerts awaiting review</span>
    <strong>{{ number_format($openAlerts) }}</strong><small>{{ number_format($alertsToday) }} generated in the past 24 hours</small>
  </div>
</div>
@include('security.alert-center')
<section class="panel" style="margin-top:20px">
  <div class="panel-head"><div><h2>Access audit <span class="count">{{ $events->total() }}</span></h2><p>IP addresses are not stored in raw form; fingerprints rotate daily.</p></div>
    <form method="get" class="table-search"><x-icon name="filter" size="16"/>
      <select name="event" class="table-select" aria-label="Filter security event">
        <option value="">All events</option>
        @foreach($availableEvents as $type)
          <option value="{{ $type }}" @selected($eventFilter === $type)>{{ str_replace('.', ' / ', $type) }}</option>
        @endforeach
      </select><button class="btn subtle" type="submit">Filter</button>
    </form>
  </div>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Time</th><th>Recorded event</th><th>Account</th><th>Public ID</th><th>Daily network fingerprint</th></tr></thead><tbody>
    @forelse($events as $event)
      <tr><td>{{ $event->created_at->format('d M Y H:i:s') }}</td><td><strong>{{ str_replace('.', ' / ', $event->event) }}</strong></td><td>{{ $event->actor?->name ?? 'Unauthenticated' }}</td><td class="mono">{{ $event->actor?->public_id ?? '—' }}</td><td class="mono">{{ $event->ip_fingerprint ?? '—' }}</td></tr>
    @empty
      <tr><td class="empty-cell" colspan="5">No events were recorded for this filter.</td></tr>
    @endforelse
  </tbody></table></div>
  <x-pagination :paginator="$events"/>
</section>
@endsection
