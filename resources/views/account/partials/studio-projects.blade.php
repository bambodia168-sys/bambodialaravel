<div class="studio-bottom-grid">
  <article class="studio-chart-card studio-projects">
    <div class="studio-card-title"><div><h3>Contract health &amp; milestones</h3>
      <p>Real freelance agreements, accepted milestones, and due dates</p></div>
      <a class="studio-small-link" href="{{ route('contracts.index', ['workspace'=>'creator']) }}">View contracts <x-icon name="arrow-up-right" size="15"/></a></div>
    <div class="studio-table-wrap">
      <table class="studio-project-table">
        <thead><tr><th scope="col">Contract &amp; client</th><th scope="col">Status</th>
          <th scope="col">Progress</th><th scope="col">Budget</th></tr></thead>
        <tbody data-studio-project-rows>
          @forelse($analytics['projects'] as $project)
            <tr>
              <td><a href="{{ $project['url'] }}"><strong>{{ $project['title'] }}</strong></a>
                <small>{{ $project['client'] }}</small></td>
              <td><span class="studio-status {{ \Illuminate\Support\Str::slug($project['status']) }}">{{ $project['status'] }}</span></td>
              <td>@if($project['progress'] !== null)<progress value="{{ $project['progress'] }}" max="100"></progress>
                <small>{{ $project['progress'] }}%</small>@else<small>No milestones</small>@endif</td>
              <td>{{ $project['value'] !== null ? '$'.number_format($project['value'],2) : $project['currency'] }}</td>
            </tr>
          @empty
            <tr><td colspan="4" class="studio-empty">No matching freelance contracts yet.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <div class="studio-timeline" data-studio-timeline>
      <h4>Contract timeline</h4>
      @php $studioMilestoneCount=0; @endphp
      @foreach($analytics['projects'] as $project)
        @foreach($project['milestones'] as $milestone)
          @if($studioMilestoneCount < 5)
            <div class="studio-timeline-row">
              <time datetime="{{ $milestone['date'] }}">{{ $milestone['date'] }}</time>
              <strong>{{ $milestone['title'] }}</strong>
              <small>{{ ucfirst(str_replace('_',' ',$milestone['status'])) }}</small>
            </div>
            @php $studioMilestoneCount++; @endphp
          @endif
        @endforeach
      @endforeach
      @if($studioMilestoneCount === 0)<p class="studio-empty">No dated milestones to display.</p>@endif
    </div>
  </article>
  <article class="studio-chart-card">
    <div class="studio-card-title"><div><h3>Activity heatmap</h3>
      <p>Last 12 weeks · logged work hours</p></div><x-icon name="calendar-days" size="18"/></div>
    <div data-chart="heatmap" class="studio-heatmap" role="img"
      aria-label="Hours recorded on each day over the past twelve weeks"></div>
    <p class="studio-chart-note">Darker cells represent more hours. Empty cells mean no time was logged, not necessarily no work.</p>
  </article>
</div>
