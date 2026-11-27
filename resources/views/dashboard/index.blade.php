@extends('layouts.dashboard')
@section('title','Overview')
@push('head')
  <link rel="stylesheet" href="{{ asset('css/role-dashboard-overview.css') }}?v=1">
  <script defer src="{{ asset('js/role-dashboard-overview.js') }}?v=1"></script>
@endpush
@section('content')
<div class="role-dashboard" data-role-dashboard="admin">
  <section class="role-dashboard-section" aria-labelledby="admin-performance">
    <div class="role-dashboard-heading">
      <div><div class="role-dashboard-eyebrow"><x-icon name="activity" size="18"/> REAL WORKSPACE DATA</div>
        <h1 id="admin-performance">{{ $isStaff ? 'Support operations' : 'Platform performance' }}</h1>
        <p>Current operational records and management actions permitted for your account.</p>
      </div>
    </div>
    @if(!$isStaff)
      <div class="workspace-overview-grid" aria-label="Platform account totals">
        <x-workspace-metric label="Registered accounts" :value="$stats['totalUsers']" icon="users"
          :href="auth()->user()->role==='super_admin' ? route('dashboard.users') : null" action="Manage accounts"/>
        <x-workspace-metric label="Approved freelancers" :value="$stats['creators']" icon="user-round-check"
          :href="auth()->user()->role==='super_admin' ? route('dashboard.users') : null" action="Manage accounts"/>
        <x-workspace-metric label="Customers and subscribers" :value="$stats['customers']" icon="user"
          :href="auth()->user()->role==='super_admin' ? route('dashboard.users') : null" action="Manage accounts"/>
      </div>
    @endif
    @if(count($reports))
      <div class="role-dashboard-charts">
        @foreach($reports as $report)
          <x-role-dashboard-report :report="$report"/>
        @endforeach
      </div>
    @endif
  </section>

  <section class="role-dashboard-section" aria-labelledby="admin-queues-title">
    <div class="role-dashboard-heading"><div><h2 id="admin-queues-title">Queues requiring attention</h2>
      <p>Open the existing permission-controlled screen for each queue. Some queues overlap.</p></div></div>
    @if(count($queues))
      <div class="workspace-overview-grid">
        @foreach($queues as $queue)
          <x-workspace-metric :label="$queue['label']" :value="$queue['count']"
            :icon="$queue['icon']" :href="route($queue['route'], $queue['params'] ?? [])" action="Manage queue"/>
        @endforeach
      </div>
    @else
      <div class="panel role-dashboard-empty"><p>No management queues are available under your current permissions.</p><a class="btn subtle" href="{{ route('dashboard.support-tickets.index') }}">My Support Tickets</a></div>
    @endif
  </section>

  @if($canReview || $canSupport)
  <section class="role-dashboard-section" aria-label="Recent operational records">
    <div class="role-dashboard-tables">
      @if($canReview)
        <section class="panel" aria-labelledby="admin-recent-title" data-dashboard-table>
          <div class="panel-head"><div><h2 id="admin-recent-title">Latest pending marketplace submissions</h2><p>Latest five records awaiting moderation.</p></div>
            <a class="btn subtle compact" href="{{ route('dashboard.reviews') }}">View queue</a></div>
          <x-role-dashboard-table-filter label="Filter recent submissions" :statuses="['pending'=>'Pending','in_review'=>'In review']"/>
          <div class="table-scroll"><table class="data-table"><thead><tr><th>Submission</th><th>Type</th><th>Status</th><th>Action</th></tr></thead><tbody>
            @foreach($recent as $item)
              <tr data-dashboard-row data-status="{{ $item->status }}">
                <td><strong>{{ $item->title }}</strong><br><small>{{ $item->created_at->format('d M Y') }}</small></td><td>{{ ucfirst($item->type) }}</td>
                <td><span class="role-pill">{{ ucfirst(str_replace('_',' ',$item->status)) }}</span></td>
                <td><a class="btn subtle compact" href="{{ $item->type === 'product' ? route('dashboard.products.index',['status'=>$item->status,'search'=>$item->title]) : route('dashboard.reviews',['type'=>$item->type,'q'=>$item->title]) }}">Review</a></td>
              </tr>
            @endforeach
            <tr data-dashboard-empty @if($recent->isNotEmpty()) hidden @endif><td class="empty-cell" colspan="4">{{ $recent->isEmpty() ? 'No items currently require review in this queue.' : 'No matching recent submissions.' }}</td></tr>
          </tbody></table></div>
        </section>
      @endif
      @if($canSupport)
        <section class="panel" aria-labelledby="admin-support-recent" data-dashboard-table>
          <div class="panel-head"><div><h2 id="admin-support-recent">Recent unresolved tickets</h2><p>Latest five open or in-progress tickets.</p></div>
            <a class="btn subtle compact" href="{{ route('dashboard.support') }}">View queue</a></div>
          <x-role-dashboard-table-filter label="Filter recent tickets" :statuses="['open'=>'Open','in_progress'=>'In progress']"/>
          <div class="table-scroll"><table class="data-table"><thead><tr><th>Ticket</th><th>Status</th><th>Updated</th><th>Action</th></tr></thead><tbody>
            @foreach($recentSupport as $item)
              <tr data-dashboard-row data-status="{{ $item->status }}"><td><strong>{{ $item->subject }}</strong></td>
                <td><span class="role-pill">{{ ucfirst(str_replace('_',' ',$item->status)) }}</span></td><td>{{ $item->updated_at->format('d M Y') }}</td>
                <td><a class="btn subtle compact" href="{{ route('dashboard.support.show',$item->public_id) }}">Open</a></td></tr>
            @endforeach
            <tr data-dashboard-empty @if($recentSupport->isNotEmpty()) hidden @endif><td class="empty-cell" colspan="4">No matching recent tickets.</td></tr>
          </tbody></table></div>
        </section>
      @endif
    </div>
  </section>
  @endif

  <section class="role-dashboard-section" aria-labelledby="admin-manage">
    <div class="role-dashboard-heading"><div><h2 id="admin-manage">Manage platform operations</h2><p>Management workspaces available to your assigned role and permissions.</p></div></div>
    <div class="role-dashboard-shortcuts">
      @foreach($shortcuts as [$label,$route,$icon])
        <a class="role-dashboard-shortcut" href="{{ route($route) }}"><x-icon :name="$icon" size="20"/><span>{{ $label }}</span><x-icon name="arrow-right" size="16"/></a>
      @endforeach
    </div>
  </section>
</div>
@endsection
