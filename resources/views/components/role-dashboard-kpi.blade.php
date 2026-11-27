@props(['label','value','icon','description','href'=>null])
@if($href)<a class="role-dashboard-kpi" href="{{ $href }}">@else<div class="role-dashboard-kpi">@endif
  <span><x-icon :name="$icon" size="17"/> {{ $label }}</span>
  <strong>{{ number_format((int)$value) }}</strong>
  <small>{{ $description }}</small>
@if($href)</a>@else</div>@endif
