@extends('layouts.main')
@section('title','Find Jobs')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Bambodia · Freelance opportunities</div><h1>Find Jobs</h1>
    <p>Browse real administrator-approved client opportunities, save interesting briefs, and apply through your freelancer workspace.</p>
  </div>
  <section class="marketplace-filter-panel">
    <form method="get" action="{{ route('jobs') }}" class="marketplace-filter-form">
      <div class="marketplace-filter-field marketplace-filter-search">
        <label for="jobs-search">Search job briefs</label>
        <input id="jobs-search" name="q" type="search" value="{{ $search }}" maxlength="120" placeholder="Job title, expertise or keyword">
      </div>
      <div class="marketplace-filter-field">
        <label for="jobs-category">Category</label>
        <select id="jobs-category" name="category">
          <option value="">All categories</option>
          @foreach(['development','design','writing','marketing','business','other'] as $value)
            <option value="{{ $value }}" @selected($category===$value)>{{ ucfirst($value) }}</option>
          @endforeach
        </select>
      </div>
      <div class="marketplace-filter-field">
        <label for="jobs-sort">Sort opportunities</label>
        <select id="jobs-sort" name="sort">
          @foreach(['recent'=>'Newest first','budget_low'=>'Budget: low to high','budget_high'=>'Budget: high to low'] as $value=>$label)
            <option value="{{ $value }}" @selected($sort===$value)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <div class="marketplace-filter-actions">
        <button class="btn primary" type="submit"><x-icon name="search" size="16"/> Search jobs</button>
        <a class="btn subtle" href="{{ route('jobs') }}">Reset</a>
      </div>
    </form>
  </section>
  <section class="marketplace-section">
    <div class="marketplace-section-heading"><div><span class="eyebrow">Approved client briefs</span>
      <h2>Open jobs <span class="count">{{ $jobs->total() }}</span></h2></div>
      @auth<a href="{{ route('account.saved-jobs') }}" class="btn subtle"><x-icon name="star" size="16"/> Saved opportunities</a>@endauth
    </div>
    @if($jobs->isNotEmpty())
    <div class="marketplace-jobs-grid">
      @foreach($jobs as $job)
      <article class="marketplace-job-card">
        <div class="eyebrow">{{ ucfirst($job->category) }} · {{ $job->public_id }}</div>
        <h3><a href="{{ $job->publicUrl() }}">{{ $job->title }}</a></h3>
        <p>{{ \Illuminate\Support\Str::limit($job->description,190) }}</p>
        <div class="marketplace-card-meta">
          <span>Published by {{ $job->client?->name }} · {{ $job->updated_at->format('d M Y') }}</span>
          <strong>{{ $job->budget===null?'Budget on request':'USD '.number_format((float)$job->budget,2) }}</strong>
        </div>
        <div class="marketplace-card-actions">
          <a class="btn subtle compact" href="{{ $job->publicUrl() }}">View opportunity</a>
          @auth
            @if(in_array((int)$job->id,$savedJobIds,true))
              <form method="post" action="{{ route('saved-jobs.destroy',$job) }}">
                @csrf @method('DELETE')
                <button class="btn subtle compact" type="submit"><x-icon name="star" size="15"/> Saved</button>
              </form>
            @else
              <form method="post" action="{{ route('saved-jobs.store',$job) }}">
                @csrf
                <button class="btn subtle compact" type="submit"><x-icon name="star" size="15"/> Save</button>
              </form>
            @endif
          @endauth
        </div>
      </article>
      @endforeach
    </div>
    @else
      <div class="marketplace-empty">No approved open jobs match this search. Try another category or reset your filters.</div>
    @endif
    <x-pagination :paginator="$jobs"/>
  </section>
  @auth
    <div class="studio-actions">
      @if(in_array(auth()->user()->role,['customer','subscriber','super_admin'],true))
        <a class="btn primary" href="{{ route('jobs.mine') }}">Manage my job posts</a>
      @endif
      @if(auth()->user()->role==='freelancer')
        <a class="btn subtle" href="{{ route('jobs.applied') }}">My submitted proposals</a>
        <a class="btn subtle" href="{{ route('resume.edit') }}">Resume Builder</a>
      @endif
    </div>
  @endauth
</div>
@endsection
