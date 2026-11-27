@extends('layouts.workspace')
@section('workspace','account')
@section('title','My Account')
@push('head')
  <link rel="stylesheet" href="{{ asset('css/customer-account-overview.css') }}?v=3">
  <link rel="stylesheet" href="{{ asset('css/role-dashboard-overview.css') }}?v=2">
  <script defer src="{{ asset('js/role-dashboard-overview.js') }}?v=2"></script>
@endpush
@section('content')
<div class="container inner-page customer-account-overview role-dashboard" data-role-dashboard="account">
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
    $primaryKeys = $orderSummary === null ? ['saved','saved_jobs','unread','contracts'] : ['saved','unread','contracts'];
    $activityKeys = array_keys($stats);
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

  <section class="account-overview-section role-dashboard-section" aria-labelledby="customer-activity-heading">
    <div class="role-dashboard-heading"><div>
      <div class="role-dashboard-eyebrow"><x-icon name="activity" size="18"/> REAL WORKSPACE DATA</div>
      <h2 id="customer-activity-heading">Marketplace activity</h2>
      <p>Your own recorded activity across the marketplace. No sample metrics.</p>
    </div><x-role-dashboard-period-filter/></div>
    <div class="role-dashboard-kpis" aria-label="Personal account performance">
      @if($orderSummary !== null)
        <x-role-dashboard-kpi label="My orders" :value="$orderTotal" icon="shopping-cart" description="All recorded marketplace purchases" :href="route('account.orders.index')"/>
      @endif
      @foreach($primaryKeys as $key)
        <x-role-dashboard-kpi :label="$accountMetrics[$key][0]" :value="$stats[$key]" :icon="$accountMetrics[$key][1]" :description="$accountMetrics[$key][3]" :href="route($accountMetrics[$key][2])"/>
      @endforeach
    </div>
    <x-role-dashboard-trends scope="account"/>
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
      </section>
    </div>
  </section>

  @if(count(array_diff_key($stats,array_flip($primaryKeys))) || $orderSummary !== null)
  <div class="workspace-overview-grid" aria-label="Your account activity">
    @foreach($stats as $key=>$count)
      @continue(in_array($key,$primaryKeys,true))
      <x-workspace-metric :label="$accountMetrics[$key][0]" :value="$count" :icon="$accountMetrics[$key][1]"
          :href="route($accountMetrics[$key][2])" :action="$accountMetrics[$key][3]"/>
    @endforeach
    @if($orderSummary !== null)
      <x-workspace-metric label="Completed Orders" :value="$orderSummary['completed']" icon="check-circle"
          :href="route('account.orders.index',['status'=>'completed'])" action="View completed orders"/>
      <x-workspace-metric label="Awaiting Payment" :value="$orderSummary['awaiting_payment']" icon="clock"
          :href="route('account.orders.index',['status'=>'awaiting_payment'])" action="Review unpaid orders"/>
    @endif
  </div>
  @endif

  <section class="account-overview-section" aria-labelledby="account-actions">
    <div class="workspace-section-head"><div><h2 id="account-actions">Action center</h2>
      <p>Important account activity that may need your attention.</p></div></div>
    <div class="panel account-action-center">
      @if(count($accountActions) === 0 && $supportReplies->isEmpty())
        <div class="account-action-empty"><span class="account-action-icon is-info"><x-icon name="check-circle" size="22"/></span>
          <div><strong>You're all caught up</strong><p>No account actions need attention right now.</p></div></div>
      @else
        @foreach($accountActions as $action)
          <div class="account-action-row">
            <span class="account-action-icon is-{{ $action['tone'] }}"><x-icon :name="$action['icon']" size="22"/></span>
            <div class="account-action-copy"><strong>{{ $action['title'] }}</strong><p>{{ $action['description'] }}</p></div>
            <a class="btn compact {{ $action['tone'] === 'attention' ? 'primary' : 'subtle' }}" href="{{ $action['href'] }}">{{ $action['label'] }}</a>
          </div>
        @endforeach
        @foreach($supportReplies as $reply)
          <div class="account-action-row">
            <span class="account-action-icon is-info"><x-icon name="message" size="22"/></span>
            <div class="account-action-copy"><strong>New support reply</strong><p>{{ $reply['subject'] }} · {{ $reply['notice']->title }}</p></div>
            <form method="post" action="{{ route('account.notifications.read',$reply['notice']) }}">
              @csrf @method('PATCH')
              <button type="submit" class="btn subtle compact">View ticket</button>
            </form>
          </div>
        @endforeach
      @endif
    </div>
  </section>

  <div class="workspace-overview-columns account-overview-section account-recent-columns">
    @if($orderSummary !== null)
      <section class="panel" aria-labelledby="account-recent-orders" data-dashboard-table>
        <div class="panel-head"><div><h2 id="account-recent-orders">Recent orders</h2>
          <p>Your latest marketplace purchases.</p></div>
          <a class="btn subtle compact" href="{{ route('account.orders.index') }}">View all</a></div>
        <x-role-dashboard-table-filter label="Filter recent orders" :statuses="$orderLabels"/>
        <div class="table-scroll"><table class="data-table"><thead><tr><th>Order</th><th>Status</th><th>Total</th><th>Action</th></tr></thead><tbody>
          @foreach($recentOrders as $order)
            <tr data-dashboard-row data-status="{{ $order->status }}">
              <td><strong>{{ $order->listing_title }}</strong><br><small>{{ $order->created_at->format('d M Y') }}</small></td>
              <td><span class="role-pill">{{ $orderLabels[$order->status] ?? ucfirst($order->status) }}</span></td>
              <td>{{ $order->currency }} {{ $order->total }}</td>
              <td><a class="btn subtle compact" href="{{ route('account.orders.show',$order->reference()) }}">View</a></td>
            </tr>
          @endforeach
          <tr data-dashboard-empty @if($recentOrders->isNotEmpty()) hidden @endif><td colspan="4" class="empty-cell">{{ $recentOrders->isEmpty() ? 'You have no recorded orders yet.' : 'No matching recent orders.' }}</td></tr>
        </tbody></table></div>
      </section>
    @endif
    <section class="panel account-recent-activity" aria-labelledby="account-recent-updates" data-dashboard-table>
      <div class="panel-head"><div><h2 id="account-recent-updates">Recent notifications</h2>
        <p>Your latest private account updates.</p></div>
        <a href="{{ route('account.notifications') }}" class="btn subtle compact">View all</a></div>
      <x-role-dashboard-table-filter label="Filter recent notifications" :statuses="['unread'=>'Unread','read'=>'Read']"/>
      @foreach($activity as $entry)
        <div class="account-activity-row" data-dashboard-row data-status="{{ $entry->read_at ? 'read' : 'unread' }}">
          <div><strong>{{ $entry->title }}</strong><p>{{ $entry->message }}</p><small>{{ $entry->created_at->format('d M Y H:i') }}</small></div>
          <form method="post" action="{{ route('account.notifications.read',$entry) }}">
            @csrf @method('PATCH')
            <button class="btn subtle compact" type="submit">Open update</button>
          </form>
        </div>
      @endforeach
      <p class="workspace-overview-empty" data-dashboard-empty @if($activity->isNotEmpty()) hidden @endif>{{ $activity->isEmpty() ? 'Your recorded marketplace activity will appear here.' : 'No matching recent account updates.' }}</p>
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
        <div class="account-profile-action">
          <a class="btn subtle" href="{{ route('account.profile.edit') }}"><x-icon name="user" size="17"/> Customize profile &amp; sections</a>
          <a class="btn subtle" href="{{ route('account.security') }}"><x-icon name="shield-check" size="17"/> Account Security</a>
          @if($user->role==='freelancer')
            <a class="btn subtle" href="{{ route('resume.edit') }}"><x-icon name="file-check" size="17"/> Resume Builder</a>
          @endif
        </div>
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

  @if(in_array($user->role,['customer','subscriber'],true))
    <section class="panel account-creator-invitation account-overview-section" aria-labelledby="account-creator-invite">
      <div class="account-creator-invitation-copy">
        <div class="account-overview-eyebrow">Grow with Bambodia</div>
        @if($creatorApplicationStatus === 'pending')
          <h2 id="account-creator-invite">Your creator application is under review.</h2>
          <p>Continue using your account while the team reviews your application.</p>
        @elseif($creatorApplicationStatus === 'approved')
          <h2 id="account-creator-invite">Your creator application has been approved.</h2>
          <p>Review your application status and access your creator workspace when available.</p>
        @elseif($creatorApplicationStatus === 'rejected')
          <h2 id="account-creator-invite">Ready to try again?</h2>
          <p>Review your previous application and update your experience before resubmitting.</p>
        @else
          <h2 id="account-creator-invite">Turn your skills into opportunities.</h2>
          <p>Sell digital products, offer professional services, showcase your projects and connect with clients.</p>
        @endif
        <div class="account-invitation-actions">
          <a class="btn primary" href="{{ route('creator.apply') }}"><x-icon name="user-plus" size="17"/>
            {{ $creatorApplicationStatus === 'pending' || $creatorApplicationStatus === 'approved' ? 'View application' : ($creatorApplicationStatus === 'rejected' ? 'Review application' : 'Become a creator') }}</a>
          
        </div>
      </div>
      <div class="account-invitation-art" aria-hidden="true">
        <svg viewBox="0 0 160 128" fill="none" xmlns="http://www.w3.org/2000/svg">
          <rect x="10" y="8" width="140" height="112" rx="20" fill="currentColor" opacity=".08"/>
          <rect x="28" y="26" width="104" height="76" rx="10" class="account-invite-art-surface"/>
          <path d="M44 85h13V68H44zm25 0h13V55H69zm25 0h13V40H94z" fill="currentColor" opacity=".45"/>
          <path d="m43 59 24-18 14 7 23-19m-13 0h13v13" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
          <circle cx="130" cy="100" r="18" fill="currentColor"/>
          <path d="M122 100h16m-8-8v16" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
        </svg>
      </div>
    </section>
  @endif
</div>
@endsection
