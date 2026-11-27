@props(['report','kind'=>'bars'])
@php
  $maximum = max(1, ...array_column($report['rows'], 'count'));
  $total = array_sum(array_column($report['rows'], 'count'));
  $colors = ['var(--dashboard-blue)','var(--dashboard-light-blue)','var(--dashboard-orange)','var(--dashboard-fourth)','var(--muted)'];
  $segments = [];
  $position = 0;
  foreach($report['rows'] as $i=>$row) {
      $end = $position + ($total ? $row['count'] / $total * 100 : 0);
      $segments[] = $colors[$i%count($colors)].' '.$position.'% '.$end.'%';
      $position = $end;
  }
@endphp
<section class="role-dashboard-chart">
  <div class="role-dashboard-card-heading"><div><h3>{{ $report['title'] }}</h3><p>{{ $report['description'] }}</p></div><x-icon :name="$report['icon']" size="19"/></div>
  @if($kind === 'donut')
    <div class="role-dashboard-donut-layout">
      <div class="role-dashboard-donut" aria-hidden="true" style="background:{{ $total ? 'conic-gradient('.implode(',',$segments).')' : 'var(--dashboard-track)' }}">
        <div class="role-dashboard-donut-center"><strong>{{ number_format($total) }}</strong><small>Records</small></div>
      </div>
      <div class="role-dashboard-legend">
        @foreach($report['rows'] as $i=>$row)
          @if(!empty($row['href']))<a class="role-dashboard-legend-row" href="{{ $row['href'] }}">@else<div class="role-dashboard-legend-row">@endif
            <span style="--dot:{{ $colors[$i%count($colors)] }}">{{ $row['label'] }}</span><strong>{{ number_format($row['count']) }}</strong>
          @if(!empty($row['href']))</a>@else</div>@endif
        @endforeach
      </div>
    </div>
    <p class="role-dashboard-note">Distribution of recorded statuses.</p>
  @else
  <div class="role-dashboard-bars">
    @foreach($report['rows'] as $row)
      @if(!empty($row['href']))<a class="role-dashboard-bar" href="{{ $row['href'] }}">@else<div class="role-dashboard-bar">@endif
        <span><span>{{ $row['label'] }}</span><strong>{{ number_format($row['count']) }}</strong></span>
        <span class="role-dashboard-track" aria-hidden="true"><i style="width:{{ $row['count'] / $maximum * 100 }}%"></i></span>
      @if(!empty($row['href']))</a>@else</div>@endif
    @endforeach
  </div>
  <p class="role-dashboard-note">Bar lengths compare counts, not percentages.</p>
  @endif
</section>
