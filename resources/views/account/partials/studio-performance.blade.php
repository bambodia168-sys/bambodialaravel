<section class="studio-analytics" id="studio-analytics" data-studio-analytics
  data-endpoint="{{ route('studio.analytics.data') }}" aria-labelledby="studio-analytics-title">
  <div class="studio-analytics-heading">
    <div>
      <div class="studio-eyebrow"><x-icon name="chart-no-axes-combined" size="18"/> REAL WORKSPACE DATA</div>
      <h2 id="studio-analytics-title">Business performance</h2>
      <p>Verified USD sales, logged work, and real client activity. No sample metrics.</p>
    </div>
    <div class="studio-filter-sets">
      <div class="studio-filter-group" role="group" aria-label="Filter by workstream">
        @foreach(['all'=>'All','product'=>'Products','project'=>'Projects','freelance'=>'Freelance','service'=>'Services'] as $type=>$label)
          <button type="button" data-filter-type="{{ $type }}" aria-pressed="{{ $type === 'all' ? 'true' : 'false' }}"
            class="{{ $type === 'all' ? 'is-active' : '' }}">{{ $label }}</button>
        @endforeach
      </div>
      <div class="studio-filter-group" role="group" aria-label="Reporting period">
        @foreach(['month'=>'This month','quarter'=>'Quarter','year'=>'Year'] as $period=>$label)
          <button type="button" data-filter-period="{{ $period }}" aria-pressed="{{ $period === 'month' ? 'true' : 'false' }}"
            class="{{ $period === 'month' ? 'is-active' : '' }}">{{ $label }}</button>
        @endforeach
      </div>
    </div>
  </div>
  <p class="studio-analytics-error" data-studio-error role="alert" hidden></p>
  <div class="studio-analytics-kpis" aria-live="polite">
    <div class="studio-mini-kpi"><span><x-icon name="wallet" size="17"/> Verified sales (USD)</span>
      <strong data-kpi="total_revenue">&#36;{{ number_format($analytics['total_revenue'], 2) }}</strong>
      <small>Completed orders with verified payment, excluding refunds</small></div>
    <div class="studio-mini-kpi"><span><x-icon name="clock-3" size="17"/> Billable hours logged</span>
      <strong data-kpi="billable_hours">{{ number_format($analytics['billable_hours'], 2) }} h</strong>
      <small data-effective-rate>Gross sales divided by logged billable hours; not a contracted hourly rate.</small></div>
    <div class="studio-mini-kpi"><span><x-icon name="coins" size="17"/> Sales minus logged expenses</span>
      <strong data-kpi="sales_minus_expenses">&#36;{{ number_format($analytics['sales_minus_expenses'], 2) }}</strong>
      <small data-observed-margin>Not net profit: excludes fees, taxes and unrecorded costs</small></div>
    <div class="studio-mini-kpi"><span><x-icon name="briefcase-business" size="17"/> Contracts displayed</span>
      <strong data-kpi="projects">{{ count($analytics['projects']) }}</strong>
      <small>Creator-owned freelance agreements</small></div>
  </div>
  <div class="studio-chart-grid">
    <article class="studio-chart-card studio-chart-wide">
      <div class="studio-card-title"><div><h3>Verified sales vs. goal</h3>
        <p>USD · Paid orders · <span data-window-start>{{ $analytics['start'] }}</span> onward</p></div>
        @if(config('commerce.enabled'))<a href="{{ route('studio.commerce-operations') }}" class="studio-small-link">Sales details <x-icon name="arrow-up-right" size="15"/></a>@endif</div>
      <div class="studio-chart-total" data-total-revenue>&#36;{{ number_format($analytics['total_revenue'], 2) }}</div>
      <div class="studio-chart-canvas" data-chart="revenue" role="img" aria-label="Verified sales compared with a configured goal"></div>
      <p class="studio-chart-note">Only verified completed USD orders. Set a goal below to compare.</p>
    </article>
    <article class="studio-chart-card studio-chart-wide">
      <div class="studio-card-title"><div><h3>Billable vs. non-billable hours</h3><p>Time you record in Creator Studio</p></div>
        <x-icon name="clock-3" size="18"/></div>
      <div class="studio-chart-total" data-hours-total>{{ number_format($analytics['billable_hours'] + $analytics['non_billable_hours'], 2) }} h</div>
      <div class="studio-chart-canvas" data-chart="hours" role="img" aria-label="Billable and non-billable hours over time"></div>
      <p class="studio-chart-note">Blue = billable · Light blue = non-billable. Missing hours are not estimated.</p>
    </article>
    <article class="studio-chart-card">
      <div class="studio-card-title"><div><h3>Order payment status</h3><p>Actual orders, not invoices</p></div>
        <x-icon name="receipt-text" size="18"/></div>
      <div data-chart="orders" class="studio-donut-layout" role="img" aria-label="Completed, awaiting-payment and refunded orders"></div>
      @if(config('commerce.enabled'))<a class="studio-small-link" href="{{ route('studio.orders.index') }}">View sales orders <x-icon name="arrow-up-right" size="15"/></a>@endif
    </article>
    <article class="studio-chart-card">
      <div class="studio-card-title"><div><h3>Weekly capacity</h3><p>Logged time vs. target</p></div>
        <x-icon name="gauge" size="18"/></div>
      <div data-chart="capacity" class="studio-capacity-layout" role="img" aria-label="Recorded hours relative to weekly capacity target"></div>
      <a href="#studio-activity" class="studio-small-link">Set weekly target <x-icon name="arrow-down" size="15"/></a>
    </article>
    <article class="studio-chart-card studio-chart-wide">
      <div class="studio-card-title"><div><h3>Sales and expenses</h3><p>Verified sales vs. manually logged expenses</p></div>
        <x-icon name="chart-line" size="18"/></div>
      <div data-chart="expenses" class="studio-chart-canvas" role="img" aria-label="Sales and expenses over time"></div>
      <p class="studio-chart-note">Fees, taxes and refunds are not automatically entered as expenses.</p>
    </article>
    <article class="studio-chart-card">
      <div class="studio-card-title"><div><h3>Repeat purchase share</h3><p>Repeated purchases in this period</p></div>
        <x-icon name="users-round" size="18"/></div>
      <div data-chart="retention" class="studio-donut-layout" role="img" aria-label="Revenue from repeat and single purchases"></div>
      <p class="studio-chart-note">Repeat buyers are not necessarily retainer clients.</p>
    </article>
    <article class="studio-chart-card">
      <div class="studio-card-title"><div><h3>Income by workstream</h3><p>Verified sales mix across listing types</p></div>
        <x-icon name="chart-pie" size="18"/></div>
      <div data-chart="workstreams" class="studio-donut-layout" role="img"
        aria-label="Verified USD sales breakdown by listing type"></div>
      <p class="studio-chart-note">Each portion comes from a real paid marketplace order.</p>
    </article>
    <article class="studio-chart-card">
      <div class="studio-card-title"><div><h3>Pipeline activity</h3><p>Not a cohort conversion rate</p></div>
        <x-icon name="list-filter" size="18"/></div>
      <div data-chart="pipeline" class="studio-pipeline" aria-label="Inquiries, proposals and accepted contracts"></div>
      <a class="studio-small-link" href="{{ route('studio.inquiries') }}">Review inquiries <x-icon name="arrow-up-right" size="15"/></a>
    </article>
  </div>
  <script type="application/json" id="studio-analytics-payload">@json($analytics)</script>
</section>
