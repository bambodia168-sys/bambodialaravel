<div class="admin-performance-filters" data-admin-performance-filters>
  <div class="role-dashboard-filter-group" role="group" aria-label="Filter by marketplace type">
    @foreach(\App\Support\AdminDashboardFilters::TYPES as $type=>$label)
      <a href="{{ url()->current().'?'.http_build_query(['type'=>$type,'period'=>$filters->period]) }}"
         data-admin-filter-type="{{ $type }}" @class(['is-active'=>$filters->type===$type])
         @if($filters->type===$type) aria-current="true" @endif>{{ $label }}</a>
    @endforeach
  </div>
  <div class="role-dashboard-filter-group" role="group" aria-label="Reporting period">
    @foreach(\App\Support\AdminDashboardFilters::PERIODS as $period=>$label)
      <a href="{{ url()->current().'?'.http_build_query(['type'=>$filters->type,'period'=>$period]) }}"
         data-admin-filter-period="{{ $period }}" @class(['is-active'=>$filters->period===$period])
         @if($filters->period===$period) aria-current="true" @endif>{{ $label }}</a>
    @endforeach
  </div>
  <p class="admin-performance-range" data-admin-report-range>{{ $filters->rangeLabel() }}</p>
</div>
