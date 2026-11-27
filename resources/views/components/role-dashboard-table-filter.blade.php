@props(['label','statuses'=>[]])
<div class="role-dashboard-table-filter" data-dashboard-controls hidden>
  <label><span>{{ $label }}</span><input type="search" data-dashboard-search placeholder="Search displayed records" autocomplete="off"></label>
  @if(count($statuses))
    <label><span>Status</span><select data-dashboard-status><option value="">All statuses</option>
      @foreach($statuses as $key=>$name)<option value="{{ $key }}">{{ $name }}</option>@endforeach
    </select></label>
  @endif
  <button type="button" class="btn subtle compact" data-dashboard-reset>Clear</button>
  <span class="role-dashboard-note" data-dashboard-count aria-live="polite"></span>
</div>
