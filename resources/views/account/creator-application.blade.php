@extends('layouts.workspace')
@section('workspace','account')
@section('title','Creator Application')
@section('content')
<div class="container inner-page creator-onboarding">
  <div class="page-intro"><div class="eyebrow">Creator onboarding</div><h1>Become a Bambodia creator</h1><p>Apply to publish your own professional services, digital projects and licensed products.</p></div>
  <section class="panel">
    <div class="panel-head"><h2>Your application</h2>
      @if($application)<span class="role-pill">{{ ucfirst($application->status) }}</span>@else<span class="role-pill">Not submitted</span>@endif
    </div>
    <div class="onboarding-content">
      @if($application && $application->status === 'pending')
        <p>Your creator application is awaiting an administrator decision. You can continue using your customer account while it is reviewed.</p>
        <dl class="detail-list"><div><dt>Specialization</dt><dd>{{ $application->specialization }}</dd></div><div><dt>Submitted</dt><dd>{{ $application->updated_at->format('d M Y') }}</dd></div></dl>
      @elseif($application && $application->status === 'approved')
        <p>Your application has been approved. Your creator workspace is available.</p>
        <a class="btn primary" href="{{ url('/studio?='.auth()->user()->public_id) }}">Open Creator Studio</a>
      @else
        @if($application?->status === 'rejected')
          <p>The previous application was not approved. You may update your details and submit a new application.</p>
          @if($application->review_note)<p><strong>Review feedback:</strong> {{ $application->review_note }}</p>@endif
        @endif
        <form action="{{ route('creator.apply.store') }}" method="post" class="creator-application-form">
          @csrf
          <label for="creator-specialization">Professional specialization</label>
          <input id="creator-specialization" name="specialization" maxlength="180" minlength="4" required value="{{ old('specialization',$application?->specialization) }}" placeholder="Your primary creative specialty">
          <label for="creator-experience">Experience and services you plan to offer</label>
          <textarea id="creator-experience" name="experience" minlength="60" maxlength="6000" rows="9" required>{{ old('experience',$application?->experience) }}</textarea>
          <label for="creator-portfolio">Portfolio or professional website (optional, HTTPS only)</label>
          <input id="creator-portfolio" name="portfolio_url" type="url" maxlength="400" value="{{ old('portfolio_url',$application?->portfolio_url) }}" placeholder="https://">
          <p class="hint">Approval is required before creator publishing rights are enabled. Do not include private credentials or confidential client data.</p>
          <button class="btn primary" type="submit">Submit creator application</button>
        </form>
      @endif
    </div>
  </section>
</div>
@endsection
