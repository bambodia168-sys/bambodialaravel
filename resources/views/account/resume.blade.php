@extends('layouts.workspace')
@section('workspace','creator')
@section('title','Resume Builder')
@section('content')
<div class="container inner-page creator-onboarding">
 <div class="page-intro"><div class="eyebrow">Freelancer workspace</div><h1>Resume Builder</h1><p>Maintain a professional profile for job proposals. Your resume is shown only to job owners when you apply, if you choose to publish it.</p></div>
 <section class="panel"><div class="panel-head"><h2>Professional experience</h2><span class="role-pill">{{ $resume?->is_visible ? 'Published to job owners' : 'Private' }}</span></div>
 <div class="onboarding-content">
 <form method="post" action="{{ route('resume.update') }}" class="creator-application-form">
 @csrf @method('PATCH')
 <label for="resume-headline">Professional headline</label><input id="resume-headline" name="headline" minlength="8" maxlength="180" value="{{ old('headline',$resume?->headline) }}" required>
 <label for="resume-summary">Professional summary</label><textarea id="resume-summary" name="summary" minlength="40" maxlength="4000" rows="5" required>{{ old('summary',$resume?->summary) }}</textarea>
 <label for="resume-experience">Work experience and projects</label><textarea id="resume-experience" name="experience" minlength="40" maxlength="6000" rows="8" required>{{ old('experience',$resume?->experience) }}</textarea>
 <label for="resume-skills">Skills and competencies</label><textarea id="resume-skills" name="skills" minlength="5" maxlength="2000" rows="5" required>{{ old('skills',$resume?->skills) }}</textarea>
 <label for="resume-portfolio">Portfolio (HTTPS only, optional)</label><input id="resume-portfolio" name="portfolio_url" type="url" maxlength="400" value="{{ old('portfolio_url',$resume?->portfolio_url) }}">
 <label for="resume-visibility">Visibility</label><select id="resume-visibility" name="is_visible" required><option value="0" @selected(!old('is_visible',$resume?->is_visible ?? false))>Private — do not allow new applications</option><option value="1" @selected(old('is_visible',$resume?->is_visible ?? false))>Published — available to job owners after applying</option></select>
 <button class="btn primary" type="submit">Save resume</button>
 </form></div></section>
</div>
@endsection
