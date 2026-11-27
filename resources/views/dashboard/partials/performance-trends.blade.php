<div class="role-dashboard-trends" data-dashboard-trends data-admin-performance-trends>
  @foreach($adminTrends as $chart)
    @php
      $buckets = $chart['buckets'];
      $total = array_sum(array_column($buckets, 'count'));
      $step = max(1, (int)ceil(max(0,...array_column($buckets, 'count')) / 4));
      $maximum = $step * 4;
      $points = [];
      foreach ($buckets as $i=>$bucket) {
        $points[] = (count($buckets) === 1 ? 230 : 42 + $i * 376 / (count($buckets)-1)).','.(154 - $bucket['count'] / $maximum * 134);
      }
    @endphp
    <article class="role-dashboard-chart role-dashboard-trend">
      <div class="role-dashboard-card-heading"><div><h3>{{ $chart['title'] }}</h3><p>{{ $chart['description'] }}</p></div><x-icon :name="$chart['icon']" size="18"/></div>
      <div class="role-dashboard-chart-total">{{ number_format($total) }}</div>
      <svg class="role-dashboard-svg" viewBox="0 0 450 188" role="img" aria-label="{{ $chart['title'] }}: {{ $total }} records, {{ $filters->rangeLabel() }}">
        @for($tick=0;$tick<=4;$tick++)
          @php($y=154-$tick*33.5)
          <line class="role-dashboard-gridline" x1="42" y1="{{ $y }}" x2="418" y2="{{ $y }}"/>
          <text class="role-dashboard-tick" x="34" y="{{ $y+4 }}" text-anchor="end">{{ $tick*$step }}</text>
        @endfor
        @if(count($points)>1)
          <polygon class="role-dashboard-area" points="42,154 {{ implode(' ',$points) }} 418,154"/>
          <polyline class="role-dashboard-line" points="{{ implode(' ',$points) }}"/>
        @endif
        @foreach($buckets as $i=>$bucket)
          @php($xy=explode(',',$points[$i]))
          <circle class="role-dashboard-dot" cx="{{ $xy[0] }}" cy="{{ $xy[1] }}" r="3"><title>{{ $bucket['label'] }}: {{ $bucket['count'] }}</title></circle>
          @if(count($buckets)<=6 || $i%3===0 || ($i===count($buckets)-1 && $i%3!==1))
            <text class="role-dashboard-tick" x="{{ $xy[0] }}" y="174" text-anchor="middle">{{ $bucket['label'] }}</text>
          @endif
        @endforeach
      </svg>
      <p class="role-dashboard-note">{{ $chart['interval'] }} counts · {{ $filters->rangeLabel() }}</p>
    </article>
  @endforeach
</div>
