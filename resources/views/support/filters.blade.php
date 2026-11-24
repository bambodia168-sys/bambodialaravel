<form method="get" action="{{ $action }}" class="support-table-filters" role="search" aria-label="Filter support tickets">
  <label class="support-filter-field support-search-field">
    <span>Search tickets</span>
    <input type="search" name="q" aria-label="Search support tickets" maxlength="120" value="{{ $filters['q'] }}" placeholder="Ticket ID or subject">
  </label>
  <label class="support-filter-field">
    <span>Status</span>
    <select name="status" aria-label="Support ticket status">
      <option value="">All statuses</option>
      @foreach(\App\Models\SupportTicket::STATUSES as $key => $label)
        <option value="{{ $key }}" @selected($filters['status'] === $key)>{{ $label }}</option>
      @endforeach
    </select>
  </label>
  <label class="support-filter-field">
    <span>Request type</span>
    <select name="issue_type" aria-label="Support request type">
      <option value="">All request types</option>
      @foreach(\App\Support\SupportTicketContext::ISSUES as $key => $label)
        <option value="{{ $key }}" @selected(($filters['issue_type'] ?? '') === $key)>{{ $label }}</option>
      @endforeach
    </select>
  </label>
  @if($management)
    <label class="support-filter-field">
      <span>Assigned to</span>
      <select name="assigned_to">
        <option value="">All assignees</option>
        <option value="unassigned" @selected($filters['assigned_to'] === 'unassigned')>Unassigned</option>
        @foreach($assignees as $agent)
          <option value="{{ $agent->id }}" @selected($filters['assigned_to'] === (string) $agent->id)>{{ $agent->name }}</option>
        @endforeach
      </select>
    </label>
  @endif
  <label class="support-filter-field">
    <span>Updated from</span>
    <input type="date" name="updated_from" value="{{ $filters['updated_from'] }}">
  </label>
  <label class="support-filter-field">
    <span>Updated to</span>
    <input type="date" name="updated_to" value="{{ $filters['updated_to'] }}">
  </label>
  <label class="support-filter-field support-size-field">
    <span>Per page</span>
    <select name="per_page">
      @foreach([10,20,30] as $size)
        <option value="{{ $size }}" @selected($filters['per_page'] === $size)>{{ $size }} items</option>
      @endforeach
    </select>
  </label>
  <div class="support-filter-actions">
    <button class="btn primary" type="submit"><x-icon name="filter" size="16"/> Apply</button>
    <a class="btn subtle" href="{{ $action }}">Reset</a>
  </div>
</form>
