@props(['report'])
@php
  $maximum = max(1, ...array_column($report['rows'], 'count'));
@endphp
<section class="role-dashboard-chart">
  <div class="role-dashboard-card-heading"><div><h3>{{ $report['title'] }}</h3><p>{{ $report['description'] }}</p></div><x-icon :name="$report['icon']" size="19"/></div>
  <div class="role-dashboard-bars">
    @foreach($report['rows'] as $row)
      @if(!empty($row['href']))<a class="role-dashboard-bar" href="{{ $row['href'] }}">@else<div class="role-dashboard-bar">@endif
        <span><span>{{ $row['label'] }}</span><strong>{{ number_format($row['count']) }}</strong></span>
        <span class="role-dashboard-track" aria-hidden="true"><i style="width:{{ $row['count'] / $maximum * 100 }}%"></i></span>
      @if(!empty($row['href']))</a>@else</div>@endif
    @endforeach
  </div>
  <p class="role-dashboard-note">Bar lengths compare counts, not percentages.</p>
</section>
