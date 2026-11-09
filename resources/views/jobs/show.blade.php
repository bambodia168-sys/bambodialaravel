@extends('layouts.main')
@section('title',$job->title)
@section('content')
@php($layoutSample = str_starts_with($job->title, '[LAYOUT TEST] '))
<div class="container inner-page listing-detail premium-detail premium-job-detail" data-detail-root>
  <x-detail-tabs kind="job" :reference="$job->public_id" :slug="$job->slug"
    :public-url="$job->publicUrl()" :sections="$jobSections" active="overview"/>

  <div class="premium-detail-layout">
    <main class="premium-detail-main">
      <x-detail-featured-media :item="$job" kind="job" type-icon="briefcase"
        :fallback-title="ucfirst($job->category)" fallback-text="Read the job brief below"/>

      <h1 class="premium-detail-below-title">{{ $job->title }}</h1>
      <div class="premium-showcase-actions">
        <div class="marketplace-detail-actions">
          @auth
            @if($isSaved)
              <form method="post" action="{{ route('saved-jobs.destroy',$job) }}">@csrf @method('DELETE')
                <button type="submit" class="btn subtle"><x-icon name="bookmark" size="16"/> Saved — remove from list</button>
              </form>
            @else
              <form method="post" action="{{ route('saved-jobs.store',$job) }}">@csrf
                <button type="submit" class="btn subtle"><x-icon name="bookmark" size="16"/> Save job</button>
              </form>
            @endif
            <a class="btn subtle" href="{{ route('account.saved-jobs') }}">My saved jobs</a>
          @else
            <a class="btn subtle" href="{{ route('login') }}">Sign in to save this job</a>
          @endauth
        </div>
        <a class="premium-action-link" href="{{ route('jobs') }}">Browse more opportunities <x-icon name="chevron-right" size="15"/></a>
      </div>

      @if($screenshots->isNotEmpty())
        <section class="panel premium-content-section" id="screenshots">
          <div class="panel-head"><div><span class="eyebrow">Visual resources</span><h2>Screenshots &amp; gallery</h2></div></div>
          <div class="builder-public-gallery">
            @foreach($screenshots as $shot)
              <a href="{{ $shot->previewUrl() }}" target="_blank" rel="noopener noreferrer"
                 aria-label="View screenshot: {{ $shot->alt_text }}">
                <img src="{{ $shot->previewUrl() }}" alt="{{ $shot->alt_text }}" loading="lazy">
              </a>
            @endforeach
          </div>
        </section>
      @endif

      <section class="panel premium-content-section" id="overview">
        <div class="panel-head"><div><span class="eyebrow">Published client brief</span><h2>Job brief</h2></div></div>
        <div class="listing-body premium-description">{!! $job->description_html ? \App\Support\BuilderContent::sanitize($job->description_html) : nl2br(e($job->description)) !!}</div>
      </section>
      <section class="panel premium-content-section" id="requirements">
        <div class="panel-head"><div><span class="eyebrow">A controlled application process</span><h2>How proposals work</h2></div></div>
        <div class="premium-steps">
          <div><span class="premium-step-number">01</span><div><strong>Review the published brief</strong><p>Check the requirements and stated budget before applying.</p></div></div>
          <div><span class="premium-step-number">02</span><div><strong>Submit a professional proposal</strong><p>Freelancers with published resumes can describe their approach and optional proposed budget.</p></div></div>
          <div><span class="premium-step-number">03</span><div><strong>Await the client decision</strong><p>The client can shortlist or decline a proposal. Applying does not create a contract or payment.</p></div></div>
        </div>
      </section>
      @auth
        @if(! $layoutSample && auth()->user()->role==='freelancer')
          <section class="panel premium-content-section" id="apply">
            <div class="panel-head"><div><span class="eyebrow">Work with this client</span><h2>Submit a proposal</h2></div></div>
            <div class="onboarding-content">
              @if($applied)
                <p>You already submitted a proposal for this job. <a class="marketplace-creator-link" href="{{ route('jobs.applied') }}">View its current status</a>.</p>
              @else
                <form action="{{ route('jobs.apply',$job) }}" method="post" class="creator-application-form">@csrf
                  <label for="proposal-message">Describe your relevant skills, scope, and approach</label>
                  <textarea id="proposal-message" name="cover_message" minlength="50" maxlength="5000" rows="7" required>{{ old('cover_message') }}</textarea>
                  <label for="proposal-budget">Your proposed budget (USD, optional)</label>
                  <input type="number" id="proposal-budget" name="proposed_budget" min="0" step="0.01" value="{{ old('proposed_budget') }}">
                  <p class="hint">A published resume is required. Submitting is an application, not a contract or payment commitment.</p>
                  <a class="btn subtle" href="{{ route('resume.edit') }}">Resume Builder</a>
                  <button class="btn primary" type="submit">Submit proposal</button>
                </form>
              @endif
            </div>
          </section>
        @elseif(! $layoutSample && (int)auth()->id()===(int)$job->client_id)
          <section class="panel premium-content-section" id="apply">
            <div class="panel-head"><h2>Manage applications</h2></div>
            <div class="listing-body"><a class="btn primary" href="{{ route('jobs.proposals',$job) }}">Review applications</a></div>
          </section>
        @endif
      @endauth
      <section class="panel premium-content-section" id="client">
        <div class="panel-head"><div><span class="eyebrow">Published by</span><h2>Client information</h2></div></div>
        <div class="premium-creator-body">
          <div class="premium-creator-avatar" aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($job->client?->name ?? 'B',0,1)) }}</div>
          <div><strong>{{ $job->client?->name ?? 'Bambodia client' }}</strong>
            <p>Client who published this approved job brief.</p></div>
        </div>
      </section>
    </main>
    <aside class="premium-detail-sidebar" aria-label="Opportunity details and actions">
      <section class="premium-side-card premium-price-card premium-offer-v3" aria-labelledby="job-offer-heading">
        <div class="premium-side-card-body">
          <div class="premium-offer-heading">
            <div>
              <span class="eyebrow">JOB OPPORTUNITY</span>
              <h2 id="job-offer-heading" class="premium-offer-title">Opportunity summary</h2>
            </div>
            @if($layoutSample)<span class="premium-offer-status">Preview listing</span>@endif
          </div>
          <div class="premium-offer-pricing">
            <div class="premium-offer-pricing-main">
              <span class="premium-offer-pricing-label">Client-provided budget</span>
              <strong>{{ $job->budget===null ? 'On request' : '$'.number_format((float)$job->budget,2) }}</strong>
            </div>
            <span class="premium-offer-currency">{{ $job->currency ?: 'USD' }}</span>
          </div>
          <ul class="premium-feature-checklist" aria-label="Job opportunity information">
            <li><span class="premium-feature-check" aria-hidden="true"><x-icon name="check" size="14"/></span><span>Published job opportunity</span></li>
            <li><span class="premium-feature-check" aria-hidden="true"><x-icon name="check" size="14"/></span><span>Save this opportunity to your account</span></li>
            <li><span class="premium-feature-check" aria-hidden="true"><x-icon name="check" size="14"/></span><span>Member comments and private support requests</span></li>
            @if(! $layoutSample)
              <li><span class="premium-feature-check" aria-hidden="true"><x-icon name="check" size="14"/></span><span>Private proposals for eligible freelancers</span></li>
            @endif
          </ul>
          <div class="premium-offer-actions">
            @if(! $layoutSample)
              @auth
                @if(auth()->user()->role==='freelancer')
                  <a class="btn primary premium-full-action" href="{{ $applied ? route('jobs.applied') : '#apply' }}">
                    {{ $applied?'View my proposal':'Apply to this job' }} <x-icon name="arrow-up-right" size="16"/>
                  </a>
                @elseif((int)auth()->id()===(int)$job->client_id)
                  <a class="btn primary premium-full-action" href="{{ route('jobs.proposals',$job) }}">Review applications <x-icon name="arrow-up-right" size="16"/></a>
                @else
                  <a class="btn subtle premium-full-action" href="{{ route('jobs') }}">Explore jobs <x-icon name="arrow-up-right" size="16"/></a>
                @endif
              @else
                <a class="btn primary premium-full-action" href="{{ route('login') }}">Sign in to apply <x-icon name="arrow-up-right" size="16"/></a>
              @endauth
            @else
              <a class="btn subtle premium-full-action" href="{{ route('jobs') }}">Browse job opportunities <x-icon name="arrow-up-right" size="16"/></a>
            @endif
          </div>
          <p class="premium-offer-footnote"><x-icon name="info" size="15"/> {{ $layoutSample ? 'Preview only — applications are not available.' : 'Applications do not constitute a contract or payment.' }}</p>
        </div>
      </section>
      <section class="premium-side-card">
        <div class="premium-side-card-heading"><h2>Information</h2></div>
        <dl class="premium-info-list">
          <div><dt>Client</dt><dd>{{ $job->client?->name ?? 'Bambodia client' }}</dd></div>
          <div><dt>Category</dt><dd>{{ ucfirst($job->category) }}</dd></div>
          <div><dt>Status</dt><dd>{{ $layoutSample ? 'Preview only' : 'Published' }}</dd></div>
          <div><dt>Created</dt><dd>{{ $job->created_at->format('d M Y') }}</dd></div>
          <div><dt>Last updated</dt><dd>{{ $job->updated_at->format('d M Y') }}</dd></div>
          <div><dt>Currency</dt><dd>{{ $job->currency ?: 'USD' }}</dd></div>
          <div><dt>Reference</dt><dd>{{ $job->public_id }}</dd></div>
        </dl>
      </section>
    </aside>
  </div>

  @if($relatedJobs->isNotEmpty())
    <section class="marketplace-section premium-related" id="related">
      <div class="marketplace-section-heading">
        <div><span class="eyebrow">Continue exploring</span><h2>Related approved jobs</h2></div>
        <a class="btn subtle compact" href="{{ route('jobs') }}">Browse job opportunities</a>
      </div>
      <div class="marketplace-card-grid premium-related-grid" data-related-grid>
        @foreach($relatedJobs->take(8) as $relatedJob)
          <x-related-job-card :job="$relatedJob"/>
        @endforeach
      </div>
    </section>
  @endif
</div>
@endsection
