@extends('layouts.workspace')
@section('workspace','account')
@section('title','My Account')
@push('head')
  <link rel="stylesheet" href="{{ asset('css/customer-account-overview.css') }}?v=1">
@endpush
@section('content')
<div class="container inner-page customer-account-overview">
  <div class="page-intro">
    <div class="eyebrow">Account workspace</div>
    <h1>My account</h1>
    <p>Welcome, {{ $user->name }}. Manage your purchases, saved work and private account activity.</p>
  </div>
  @php
    $accountMetrics = [
        'saved'=>['Saved Listings','star','account.saved-listings','View saved listings'],
        'saved_jobs'=>['Saved Jobs','bookmark','account.saved-jobs','View saved jobs'],
        'unread'=>['Unread Updates','bell','account.notifications','Open notifications'],
        'contracts'=>['Open Agreements','file-check','contracts.index','View agreements'],
        'jobs'=>['My Job Posts','briefcase','jobs.mine','Manage job posts'],
        'proposals'=>['Job Proposals','send','jobs.applied','View proposals'],
        'listings'=>['Creator Listings','layers','studio.listings','View listings'],
        'client_inquiries'=>['Open Client Inquiries','message','studio.inquiries','View inquiries'],
    ];
    $activityKeys = ['saved','saved_jobs','unread','contracts','jobs','proposals','listings','client_inquiries'];
    $activityMaximum = max(1, ...array_values($stats));
    $orderTotal = $orderSummary === null ? 0 : array_sum($orderSummary);
    $orderLabels = \App\Models\MarketplaceOrder::STATUSES;
    $orderColors = ['completed'=>'--account-blue','awaiting_payment'=>'--account-light-blue','refunded'=>'--account-orange','cancelled'=>'--account-muted'];
    $orderSegments = [];
    $position = 0;
    foreach ($orderColors as $status=>$color) {
        $end = $position + ($orderTotal > 0 ? ($orderSummary[$status] ?? 0) / $orderTotal * 100 : 0);
        $orderSegments[] = 'var('.$color.') '.$position.'% '.$end.'%';
        $position = $end;
    }
  @endphp

  <div class="workspace-overview-grid" aria-label="Your account activity">
    @foreach($stats as $key=>$count)
      <x-workspace-metric :label="$accountMetrics[$key][0]" :value="$count" :icon="$accountMetrics[$key][1]"
          :href="route($accountMetrics[$key][2])" :action="$accountMetrics[$key][3]"/>
    @endforeach
    @if($orderSummary !== null)
      <x-workspace-metric label="My Orders" :value="$orderTotal" icon="shopping-cart"
          :href="route('account.orders.index')" action="View order history"/>
      <x-workspace-metric label="Completed Orders" :value="$orderSummary['completed']" icon="check-circle"
          :href="route('account.orders.index',['status'=>'completed'])" action="View completed orders"/>
      <x-workspace-metric label="Awaiting Payment" :value="$orderSummary['awaiting_payment']" icon="clock"
          :href="route('account.orders.index',['status'=>'awaiting_payment'])" action="Review unpaid orders"/>
    @endif
  </div>

  <section class="account-overview-section" aria-labelledby="customer-activity-heading">
    <div class="workspace-section-head"><div>
      <div class="account-overview-eyebrow"><x-icon name="activity" size="16"/> Real workspace data</div>
      <h2 id="customer-activity-heading">Marketplace activity</h2>
      <p>Your own recorded activity across the marketplace. No sample metrics.</p>
    </div></div>
    <div class="account-overview-charts">
      @if($orderSummary !== null)
        <section class="account-overview-chart" aria-labelledby="account-order-status">
          <div class="account-chart-heading"><div>
            <h3 id="account-order-status">Order payment status</h3>
            <p>All-time order records, including unpaid and refunded orders.</p>
          </div><x-icon name="shopping-cart" size="19"/></div>
          <div class="account-order-chart">
            <div class="account-order-donut" aria-hidden="true"
                 style="--account-donut: {{ $orderTotal ? 'conic-gradient('.implode(',',$orderSegments).')' : 'var(--account-track)' }}">
              <div><strong>{{ number_format($orderTotal) }}</strong><small>Orders</small></div>
            </div>
            <div class="account-order-legend">
              @foreach($orderColors as $status=>$color)
                <a href="{{ route('account.orders.index',['status'=>$status]) }}" class="account-legend-row">
                  <span><i aria-hidden="true" style="--account-dot:var({{ $color }})"></i>{{ $orderLabels[$status] }}</span>
                  <strong>{{ number_format($orderSummary[$status]) }}</strong>
                </a>
              @endforeach
            </div>
          </div>
          <a class="account-chart-link" href="{{ route('account.orders.index') }}">View order history</a>
        </section>
      @endif
      <section class="account-overview-chart" aria-labelledby="account-activity-breakdown">
        <div class="account-chart-heading"><div>
          <h3 id="account-activity-breakdown">Account activity breakdown</h3>
          <p>Current counts; bar lengths compare counts, not percentages.</p>
        </div><x-icon name="layers" size="19"/></div>
        <div class="account-activity-bars">
          @foreach($activityKeys as $key)
            @continue(!array_key_exists($key,$stats))
            <a class="account-bar-row" href="{{ route($accountMetrics[$key][2]) }}">
              <span><span>{{ $accountMetrics[$key][0] }}</span><strong>{{ number_format($stats[$key]) }}</strong></span>
              <span class="account-bar-rail" aria-hidden="true"><i style="width:{{ $stats[$key] / $activityMaximum * 100 }}%"></i></span>
            </a>
          @endforeach
        </div>
        <a class="account-chart-link" href="{{ route('account.notifications') }}">Review account updates</a>
      </section>
    </div>
  </section>

  <section class="workspace-overview-block account-overview-section" aria-labelledby="account-actions">
    <div class="workspace-section-head"><div><h2 id="account-actions">Manage your account</h2>
      <p>Quick access to your purchases, work, records and support.</p></div></div>
    <div class="workspace-quick-actions">
      @if($orderSummary !== null)<a class="btn primary" href="{{ route('account.orders.index') }}"><x-icon name="shopping-cart" size="17"/> Order History</a>@endif
      <a class="btn primary" href="{{ route('account.downloads') }}"><x-icon name="download" size="17"/> My Downloads</a>
      <a class="btn subtle" href="{{ route('account.licenses.index') }}"><x-icon name="key-round" size="17"/> My Licenses</a>
      <a class="btn subtle" href="{{ route('account.saved-listings') }}"><x-icon name="star" size="17"/> Saved Listings</a>
      <a class="btn subtle" href="{{ route('account.saved-jobs') }}"><x-icon name="bookmark" size="17"/> Saved Jobs</a>
      <a class="btn subtle" href="{{ route('creators.index') }}"><x-icon name="users" size="17"/> Browse Creators</a>
      <a class="btn subtle" href="{{ route('contracts.index') }}"><x-icon name="file-check" size="17"/> My Contracts</a>
      <a class="btn subtle" href="{{ route('account.media.index') }}"><x-icon name="image" size="17"/> My Media Library</a>
      <a class="btn subtle" href="{{ route('account.notifications') }}"><x-icon name="bell" size="17"/> Notification Center</a>
      <a class="btn subtle" href="{{ route('account.security') }}"><x-icon name="shield-check" size="17"/> Account Security</a>
      <a class="btn subtle" href="{{ route('account.policy-receipts') }}"><x-icon name="book" size="17"/> Policy Receipts</a>
      <a class="btn subtle" href="{{ route('privacy.index') }}"><x-icon name="shield" size="17"/> Privacy Requests</a>
      <a class="btn subtle" href="{{ route($user->supportTicketRoutePrefix().'.index') }}"><x-icon name="life-buoy" size="17"/> My Support Tickets</a>
      @if(in_array($user->role,['customer','subscriber','super_admin'],true))
        <a class="btn subtle" href="{{ route('jobs.mine') }}"><x-icon name="briefcase" size="17"/> My Job Posts</a>
      @endif
      @if(in_array($user->role,['customer','subscriber'],true))
        <a class="btn subtle" href="{{ route('account.inquiries') }}"><x-icon name="message" size="17"/> My Service Requests</a>
      @endif
      @if($user->isCreator())
        <a class="btn subtle" href="{{ route('studio.inquiries') }}"><x-icon name="message" size="17"/> Creator Inquiries</a>
        <a class="btn subtle" href="{{ route('creator') }}"><x-icon name="palette" size="17"/> Creator Studio</a>
        <a class="btn subtle" href="{{ route('studio.profile.edit') }}"><x-icon name="user" size="17"/> Edit Creator Profile</a>
      @elseif(in_array($user->role,['customer','subscriber'],true))
        <a class="btn subtle" href="{{ route('creator.apply') }}"><x-icon name="user-plus" size="17"/> Become a creator</a>
      @endif
      @if($user->role==='freelancer')
        <a class="btn subtle" href="{{ route('resume.edit') }}"><x-icon name="file-check" size="17"/> Resume Builder</a>
        <a class="btn subtle" href="{{ route('jobs.applied') }}"><x-icon name="send" size="17"/> My Proposals</a>
      @endif
    </div>
  </section>

  <div class="workspace-overview-columns account-overview-section">
    @if($orderSummary !== null)
      <section class="panel" aria-labelledby="account-recent-orders">
        <div class="panel-head"><div><h2 id="account-recent-orders">Recent orders</h2>
          <p>Your latest marketplace purchases.</p></div>
          <a class="btn subtle compact" href="{{ route('account.orders.index') }}">View all</a></div>
        <div class="workspace-overview-list">
          @forelse($recentOrders as $order)
            <a class="workspace-overview-row" href="{{ route('account.orders.show',$order->reference()) }}">
              <span class="workspace-overview-row-main"><strong>{{ $order->listing_title }}</strong>
                <small>{{ $orderLabels[$order->status] ?? ucfirst($order->status) }} · {{ $order->created_at->format('d M Y') }}</small></span>
              <span class="workspace-overview-row-action">{{ $order->currency }} {{ $order->total }}</span>
            </a>
          @empty
            <p class="workspace-overview-empty">You have no recorded orders yet.</p>
          @endforelse
        </div>
      </section>
    @endif
    <section class="panel account-recent-activity" aria-labelledby="account-recent-updates">
      <div class="panel-head"><div><h2 id="account-recent-updates">Recent notifications</h2>
        <p>Your latest private account updates.</p></div>
        <a href="{{ route('account.notifications') }}" class="btn subtle compact">View all</a></div>
      @forelse($activity as $entry)
        <div class="account-activity-row">
          <div><strong>{{ $entry->title }}</strong><p>{{ $entry->message }}</p><small>{{ $entry->created_at->format('d M Y H:i') }}</small></div>
          <form method="post" action="{{ route('account.notifications.read',$entry) }}">
            @csrf @method('PATCH')
            <button class="btn subtle compact" type="submit">Open update</button>
          </form>
        </div>
      @empty
        <p class="workspace-overview-empty">Your recorded marketplace activity will appear here.</p>
      @endforelse
    </section>
  </div>

  <section class="panel account-overview-section" aria-labelledby="account-agreements">
    <div class="panel-head"><div><h2 id="account-agreements">Agreements needing attention</h2>
      <p>Only active, offered or disputed agreements you participate in.</p></div>
      <a class="btn subtle compact" href="{{ route('contracts.index') }}">All contracts</a>
    </div>
    <div class="workspace-overview-list">
      @forelse($agreements as $agreement)
        <a class="workspace-overview-row" href="{{ route('contracts.show',$agreement->public_id) }}">
          <span class="workspace-overview-row-main"><strong>{{ $agreement->title }}</strong>
            <small>{{ ucfirst($agreement->status) }} · {{ $agreement->updated_at->format('d M Y') }}</small>
          </span>
          <span class="workspace-overview-row-action">View contract</span>
        </a>
      @empty<p class="workspace-overview-empty">No active agreements need attention.</p>
      @endforelse
    </div>
  </section>

  <section class="account-overview-section" aria-labelledby="account-settings-heading">
    <div class="workspace-section-head"><div><h2 id="account-settings-heading">Profile and preferences</h2>
      <p>Review your account identity and personalize your experience.</p></div></div>
    <div class="workspace-overview-columns account-profile-columns">
      <section class="panel" aria-labelledby="account-profile-details">
        <div class="panel-head"><div><h2 id="account-profile-details">Profile details</h2>
          <p>Your personal account information.</p></div><span class="role-pill">{{ ucwords(str_replace('_',' ',$user->role)) }}</span></div>
        <dl class="detail-list">
          <div><dt>Public user ID</dt><dd class="mono">{{ $user->public_id }}</dd></div>
          <div><dt>Name</dt><dd>{{ $user->name }}</dd></div>
          <div><dt>Email</dt><dd>{{ $user->email }}</dd></div>
          <div><dt>Language</dt><dd>{{ strtoupper($user->locale) }}</dd></div>
        </dl>
        <div class="account-profile-action"><a class="btn subtle" href="{{ route('account.profile.edit') }}"><x-icon name="user" size="17"/> Customize profile &amp; sections</a></div>
      </section>
      <section class="panel" aria-labelledby="account-edit-profile">
        <div class="panel-head"><div><h2 id="account-edit-profile">Edit profile</h2><p>Update your display name and preferred language.</p></div></div>
        <form method="post" action="{{ route('account.update') }}" class="settings-form account-preferences-form">
          @csrf @method('PATCH')
          <label for="name">Display name</label>
          <input id="name" name="name" value="{{ old('name',$user->name) }}" maxlength="120" required autocomplete="name">
          <label for="locale">Preferred language</label>
          <select id="locale" name="locale">
            @foreach(['en'=>'English','zh'=>'Chinese','km'=>'Khmer','ko'=>'Korean','ja'=>'Japanese','th'=>'Thai','vi'=>'Vietnamese','de'=>'German'] as $code=>$label)
              <option value="{{ $code }}" @selected(old('locale',$user->locale)===$code)>{{ $label }}</option>
            @endforeach
          </select>
          <button class="btn primary" type="submit">Save changes</button>
        </form>
      </section>
    </div>
  </section>
</div>
@endsection
