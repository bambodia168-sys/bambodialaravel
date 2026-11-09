@extends('layouts.workspace')
@section('workspace','creator')
@section('title','Creator Profile Settings')
@section('content')
<div class="container inner-page creator-onboarding">
  <div class="page-intro">
    <div class="eyebrow">Creator Studio · Public presence</div>
    <h1>Creator Profile</h1>
    <p>Write a professional introduction that customers can view alongside your approved listings. Publishing is optional and never exposes private account records.</p>
  </div>
  <section class="panel">
    <div class="panel-head"><h2>Profile configuration</h2>
      <span class="role-pill">{{ $profile?->is_public?'Public':'Private' }}</span>
    </div>
    <div class="onboarding-content">
      <form method="post" action="{{ route('studio.profile.update') }}" class="creator-application-form">
        @csrf @method('PATCH')
        <label for="creator-headline">Professional headline</label>
        <input id="creator-headline" name="headline" value="{{ old('headline',$profile?->headline) }}" minlength="8" maxlength="160" required placeholder="What professional work do you offer?">
        <label for="creator-biography">Biography and professional experience</label>
        <textarea id="creator-biography" name="biography" minlength="80" maxlength="5000" rows="8" required>{{ old('biography',$profile?->biography) }}</textarea>
        <label for="creator-website">Portfolio website (optional, HTTPS only)</label>
        <input id="creator-website" name="website_url" type="url" maxlength="400" placeholder="https://example.com" value="{{ old('website_url',$profile?->website_url) }}">
        <label for="creator-visibility">Public visibility</label>
        <select name="is_public" id="creator-visibility" required>
          <option value="0" @selected((string)old('is_public',$profile?->is_public?'1':'0')==='0')>Private — do not publish a creator page</option>
          <option value="1" @selected((string)old('is_public',$profile?->is_public?'1':'0')==='1')>Public — customers can view this profile</option>
        </select>
        <p class="hint">Only your display name, 8-digit public ID, chosen headline, biography, optional portfolio website, and published approved listings appear on the public page. Never include private contact details unless you want visitors to see them.</p>
        <button class="btn primary" type="submit">Save creator profile</button>
      </form>
    </div>
  </section>
  <section class="panel">
    <div class="panel-head"><h2>My achievement badges</h2></div>
    <div class="onboarding-content"><x-creator-badges :badges="\App\Support\CreatorBadgeAwards::earned(auth()->user())"/><p class="hint">Badges are awarded automatically from verified platform activity, or manually after administrator review.</p></div>
  </section>
  <section class="panel">
    <div class="panel-head"><h2>Public portfolio preview</h2><span class="count">{{ $publishedCount }} approved listings</span></div>
    <div class="onboarding-content">
      @if($profile?->is_public)
        <p>Your profile is discoverable and can be opened from your approved marketplace listings.</p>
        <a class="btn primary" href="{{ route('creators.show',auth()->user()->public_id) }}">View public creator page</a>
      @else
        <p class="hint">Your public creator page is not visible. Set visibility to Public when you are ready to publish this information.</p>
      @endif
    </div>
  </section>
  <a class="btn subtle" href="{{ route('studio') }}">Back to Creator Studio</a>
</div>
@endsection
