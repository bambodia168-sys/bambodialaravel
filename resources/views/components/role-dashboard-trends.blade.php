@if(count($series))
<div class="role-dashboard-trends" data-dashboard-trends>
  @foreach($series as $chart)
    <article class="role-dashboard-chart role-dashboard-trend">
      <div class="role-dashboard-card-heading"><div><h3>{{ $chart['title'] }}</h3><p>{{ $chart['description'] }}</p></div><x-icon :name="$chart['icon']" size="18"/></div>
      @foreach([4,12] as $period)
        @php
          $weeks = array_slice($chart['weeks'], -$period);
          $total = array_sum(array_column($weeks,'count'));
          $step = max(1,(int)ceil(max(0,...array_column($weeks,'count')) / 4));
          $maximum = $step * 4;
          $points = [];
          foreach($weeks as $i=>$week) {
            $points[] = (42 + $i * 376 / max(1,count($weeks)-1)).','. (154 - $week['count'] / $maximum * 134);
          }
        @endphp
        <div data-trend-period="{{ $period }}" @if($period !== 4) hidden @endif>
          <div class="role-dashboard-chart-total">{{ number_format($total) }}</div>
          <svg class="role-dashboard-svg" viewBox="0 0 450 188" role="img" aria-label="{{ $chart['title'] }}: {{ $total }} records in the last {{ $period }} weeks">
            @for($tick=0;$tick<=4;$tick++)
              @php($y=154-$tick*33.5)
              <line class="role-dashboard-gridline" x1="42" y1="{{ $y }}" x2="418" y2="{{ $y }}"/>
              <text class="role-dashboard-tick" x="34" y="{{ $y+4 }}" text-anchor="end">{{ $tick*$step }}</text>
            @endfor
            <polygon class="role-dashboard-area" points="42,154 {{ implode(' ',$points) }} 418,154"/>
            <polyline class="role-dashboard-line" points="{{ implode(' ',$points) }}"/>
            @foreach($weeks as $i=>$week)
              @php($xy=explode(',',$points[$i]))
              <circle class="role-dashboard-dot" cx="{{ $xy[0] }}" cy="{{ $xy[1] }}" r="3"><title>{{ $week['label'] }}: {{ $week['count'] }}</title></circle>
              @if($period===4 || $i%3===0 || $i===count($weeks)-1)
                <text class="role-dashboard-tick" x="{{ $xy[0] }}" y="174" text-anchor="middle">{{ $week['label'] }}</text>
              @endif
            @endforeach
          </svg>
          <p class="role-dashboard-note">Weekly counts · {{ $period }} weeks ending today.</p>
        </div>
      @endforeach
    </article>
  @endforeach
</div>
@endif
