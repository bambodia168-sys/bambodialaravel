@extends('layouts.main')
@section('title','My Account')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Account workspace</div><h1>My account</h1><p>Signed in as {{ $user->name }}.</p></div>
  @if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="notice error">{{ $errors->first() }}</div>@endif
  @php
    $accountMetricRoutes=[
        'saved'=>'account.saved-listings','unread'=>'account.notifications','contracts'=>'contracts.index',
        'jobs'=>'jobs.mine','proposals'=>'jobs.applied','listings'=>'studio.listings',
        'client_inquiries'=>'studio.inquiries',
    ];
    $accountMetricLabels=[
        'saved'=>'Saved Listings','unread'=>'Unread Updates','contracts'=>'Open Agreements',
        'jobs'=>'My Job Posts','proposals'=>'Job Proposals','listings'=>'Creator Listings',
        'client_inquiries'=>'Open Client Inquiries',
    ];
  @endphp
  <div class="account-metrics" aria-label="Your account activity">
    @foreach($stats as $key=>$count)
      <a class="account-metric" href="{{ route($accountMetricRoutes[$key]) }}">
        <span>{{ $accountMetricLabels[$key] }}</span>
        <strong>{{ number_format($count) }}</strong>
        <small>Open workspace <x-icon name="chevron-right" size="13"/></small>
      </a>
    @endforeach
  </div>
  <section class="panel profile-panel-main">
    <div class="panel-head"><h2>Profile details</h2><span class="role-pill">{{ ucwords(str_replace('_',' ',$user->role)) }}</span></div>
    <dl class="detail-list"><div><dt>Public user ID</dt><dd class="mono">{{ $user->public_id }}</dd></div><div><dt>Name</dt><dd>{{ $user->name }}</dd></div><div><dt>Email</dt><dd>{{ $user->email }}</dd></div><div><dt>Language</dt><dd>{{ strtoupper($user->locale) }}</dd></div></dl>
    <form method="post" action="{{ route('account.update') }}" class="settings-form">
      @csrf @method('PATCH')
      <h3>Edit profile</h3>
      <label for="name">Display name</label><input id="name" name="name" value="{{ old('name',$user->name) }}" maxlength="120" required>
      <label for="locale">Preferred language</label>
      <select id="locale" name="locale">
        @foreach(['en'=>'English','zh'=>'Chinese','km'=>'Khmer','ko'=>'Korean','ja'=>'Japanese','th'=>'Thai','vi'=>'Vietnamese','de'=>'German'] as $code=>$label)<option value="{{ $code }}" @selected(old('locale',$user->locale)===$code)>{{ $label }}</option>@endforeach
      </select>
      <button class="btn primary" type="submit">Save changes</button>
    </form>
  </section>
  <div class="studio-actions"><a class="btn subtle" href="{{ route('account.saved-listings') }}"><x-icon name="star" size="16"/> Saved Listings</a><a class="btn subtle" href="{{ route('creators.index') }}"><x-icon name="users" size="16"/> Browse Creators</a><a class="btn subtle" href="{{ route('account.notifications') }}"><x-icon name="mail" size="16"/> Notification Center</a><a class="btn subtle" href="{{ route('account.policy-receipts') }}"><x-icon name="book" size="16"/> Policy Receipts</a><a class="btn subtle" href="{{ route('privacy.index') }}"><x-icon name="shield" size="16"/> Privacy Requests</a><a class="btn subtle" href="{{ route('contracts.index') }}"><x-icon name="file" size="16"/> My Contracts</a><a class="btn primary" href="{{ route('account.downloads') }}"><x-icon name="download" size="16"/> My Downloads</a>
  @if(in_array($user->role,['customer','subscriber','super_admin'],true))<a class="btn subtle" href="{{ route('jobs.mine') }}">My Job Posts</a>@endif
  @if(in_array($user->role,['customer','subscriber'],true))<a class="btn subtle" href="{{ route('account.inquiries') }}">My Service Requests</a>@endif
  @if($user->isCreator())<a class="btn subtle" href="{{ route('studio.inquiries') }}">Creator Inquiries</a>@endif
  @if($user->role==='freelancer')<a class="btn subtle" href="{{ route('resume.edit') }}">Resume Builder</a><a class="btn subtle" href="{{ route('jobs.applied') }}">My Proposals</a>@endif
  </div>
  @if($user->isCreator())<a class="btn subtle" href="{{ url('/studio?='.$user->public_id) }}">Creator Studio</a><a class="btn subtle" href="{{ route('studio.profile.edit') }}">Edit Creator Profile</a>
  @elseif(in_array($user->role, ['customer','subscriber'], true))<a class="btn subtle" href="{{ route('creator.apply') }}">Become a creator</a>@endif
  <section class="panel account-recent-activity">
    <div class="panel-head"><h2>Recent notifications</h2><a href="{{ route('account.notifications') }}" class="btn subtle compact">View all</a></div>
    @forelse($activity as $entry)
      <div class="account-activity-row">
        <div><strong>{{ $entry->title }}</strong><p>{{ $entry->message }}</p><small>{{ $entry->created_at->format('d M Y H:i') }}</small></div>
        <form method="post" action="{{ route('account.notifications.read',$entry) }}">
          @csrf @method('PATCH')
          <button class="btn subtle compact" type="submit">View update</button>
        </form>
      </div>
    @empty
      <div class="marketplace-empty">Your recorded marketplace activity will appear here.</div>
    @endforelse
  </section>
</div>
@endsection
