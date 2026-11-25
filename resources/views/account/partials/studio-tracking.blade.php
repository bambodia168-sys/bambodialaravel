<section id="studio-activity" class="studio-record-panel" aria-labelledby="studio-activity-title">
  <div class="studio-card-title"><div><h3 id="studio-activity-title">Track work and set targets</h3>
    <p>Private creator-owned records drive your charts and capacity indicators.</p>
  </div><x-icon name="sliders-horizontal" size="19"/></div>
  @if(session('status'))<p class="studio-form-notice" role="status">{{ session('status') }}</p>@endif
  @if($errors->any())<div class="studio-form-errors" role="alert"><strong>Please review your inputs.</strong>
    <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
  <div class="studio-editor-grid">
    <details class="studio-editor-card" @if($errors->hasAny(['monthly_goal_usd','weekly_capacity_hours'])) open @endif>
      <summary><x-icon name="target" size="18"/> Revenue &amp; capacity goals <x-icon name="chevron-down" size="17"/></summary>
      <form action="{{ route('studio.analytics.preferences') }}" method="post">
        @csrf
        <label for="studio-revenue-goal">Monthly USD sales goal</label>
        <input id="studio-revenue-goal" type="number" name="monthly_goal_usd" min="0" max="100000000" step="0.01"
          placeholder="Not configured" value="{{ old('monthly_goal_usd',$analytics['monthly_goal']) }}">
        <label for="studio-capacity">Weekly capacity (hours)</label>
        <input id="studio-capacity" type="number" name="weekly_capacity_hours" min="1" max="168" step="0.5"
          placeholder="Not configured" value="{{ old('weekly_capacity_hours',$analytics['weekly_capacity_hours']) }}">
        <button type="submit" class="btn primary">Save targets</button>
      </form>
    </details>
    <details class="studio-editor-card" @if($errors->hasAny(['logged_on','hours','is_billable'])) open @endif>
      <summary><x-icon name="clock-3" size="18"/> Log working time <x-icon name="chevron-down" size="17"/></summary>
      <form action="{{ route('studio.analytics.time.store') }}" method="post">
        @csrf
        <label for="studio-work-day">Work date</label>
        <input id="studio-work-day" type="date" name="logged_on" value="{{ old('logged_on',now()->toDateString()) }}"
          max="{{ now()->toDateString() }}" required>
        <label for="studio-time-hours">Hours</label>
        <input id="studio-time-hours" type="number" name="hours" min=".25" max="24" step=".25" value="{{ old('hours',1) }}" required>
        <label for="studio-time-type">Workstream</label>
        <select id="studio-time-type" name="work_type" required>
          @foreach(['service'=>'Service','freelance'=>'Freelance','project'=>'Project','product'=>'Product'] as $key=>$value)
            <option value="{{ $key }}" @selected(old('work_type','service') === $key)>{{ $value }}</option>
          @endforeach
        </select>
        <label for="studio-billable">Hour classification</label>
        <select id="studio-billable" name="is_billable" required><option value="1" @selected(old('is_billable','1') === '1')>Billable</option>
          <option value="0" @selected(old('is_billable') === '0')>Non-billable</option></select>
        <label for="studio-time-note">Description (optional)</label>
        <input id="studio-time-note" name="description" maxlength="240" value="{{ old('description') }}" placeholder="What did you work on?">
        <button type="submit" class="btn primary">Log hours</button>
      </form>
    </details>
    <details class="studio-editor-card" @if($errors->hasAny(['spent_on','amount_usd','category'])) open @endif>
      <summary><x-icon name="receipt" size="18"/> Log an expense <x-icon name="chevron-down" size="17"/></summary>
      <form action="{{ route('studio.analytics.expense.store') }}" method="post">
        @csrf
        <label for="studio-expense-day">Expense date</label>
        <input id="studio-expense-day" type="date" name="spent_on" value="{{ old('spent_on',now()->toDateString()) }}"
          max="{{ now()->toDateString() }}" required>
        <label for="studio-expense-amount">USD amount</label>
        <input id="studio-expense-amount" type="number" name="amount_usd" min=".01" step=".01" max="100000000" required placeholder="0.00">
        <label for="studio-expense-type">Workstream</label>
        <select id="studio-expense-type" name="work_type" required>
          @foreach(['service'=>'Service','freelance'=>'Freelance','project'=>'Project','product'=>'Product'] as $key=>$value)
            <option value="{{ $key }}" @selected(old('work_type','service') === $key)>{{ $value }}</option>
          @endforeach
        </select>
        <label for="studio-expense-category">Expense category</label>
        <select id="studio-expense-category" name="category" required>
          @foreach(['software'=>'Software','contractor'=>'Contractor','marketing'=>'Marketing','equipment'=>'Equipment','other'=>'Other'] as $key=>$value)
            <option value="{{ $key }}" @selected(old('category') === $key)>{{ $value }}</option>
          @endforeach
        </select>
        <label for="studio-expense-note">Description (optional)</label>
        <input id="studio-expense-note" name="description" maxlength="240" placeholder="Expense purpose">
        <button type="submit" class="btn primary">Log expense</button>
      </form>
    </details>
  </div>
  <div class="studio-recent-records">
    <div><h4>Recent time entries</h4>
      <div data-studio-time-rows>
        @forelse($analytics['recent_time'] as $entry)
          <div class="studio-record-row"><span><strong>{{ $entry['hours'] }}h · {{ ucfirst($entry['type']) }}</strong>
            <small>{{ $entry['date'] }} · {{ $entry['billable'] ? 'Billable' : 'Non-billable' }} {{ $entry['description'] ? '· '.$entry['description'] : '' }}</small></span>
            <form action="{{ route('studio.analytics.time.destroy',$entry['id']) }}" method="post">@csrf @method('DELETE')
              <button class="studio-delete" type="submit" aria-label="Delete time entry">Delete</button></form></div>
        @empty<p class="studio-empty">No time entries this period.</p>@endforelse
      </div>
    </div>
    <div><h4>Recent expenses</h4>
      <div data-studio-expense-rows>
        @forelse($analytics['recent_expenses'] as $entry)
          <div class="studio-record-row"><span><strong>&#36;{{ number_format($entry['amount'],2) }} · {{ ucfirst($entry['category']) }}</strong>
            <small>{{ $entry['date'] }} · {{ ucfirst($entry['type']) }} {{ $entry['description'] ? '· '.$entry['description'] : '' }}</small></span>
            <form action="{{ route('studio.analytics.expense.destroy',$entry['id']) }}" method="post">@csrf @method('DELETE')
              <button class="studio-delete" type="submit" aria-label="Delete expense">Delete</button></form></div>
        @empty<p class="studio-empty">No expense entries this period.</p>@endforelse
      </div>
    </div>
  </div>
</section>
