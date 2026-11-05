@extends('layouts.main')
@section('title','My Account')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Account workspace</div><h1>My account</h1><p>Signed in as {{ $user->name }}.</p></div>
  @if(session('status'))<div class="notice success">{{ session('status') }}</div>@endif
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
  <div class="studio-actions"><a class="btn subtle" href="{{ route('account.notifications') }}"><x-icon name="mail" size="16"/> Notification Center</a><a class="btn subtle" href="{{ route('account.policy-receipts') }}"><x-icon name="book" size="16"/> Policy Receipts</a><a class="btn subtle" href="{{ route('contracts.index') }}"><x-icon name="file" size="16"/> My Contracts</a><a class="btn primary" href="{{ route('account.downloads') }}"><x-icon name="download" size="16"/> My Downloads</a>
  @if(in_array($user->role,['customer','subscriber','super_admin'],true))<a class="btn subtle" href="{{ route('jobs.mine') }}">My Job Posts</a>@endif
  @if(in_array($user->role,['customer','subscriber'],true))<a class="btn subtle" href="{{ route('account.inquiries') }}">My Service Requests</a>@endif
  @if($user->isCreator())<a class="btn subtle" href="{{ route('studio.inquiries') }}">Creator Inquiries</a>@endif
  @if($user->role==='freelancer')<a class="btn subtle" href="{{ route('resume.edit') }}">Resume Builder</a><a class="btn subtle" href="{{ route('jobs.applied') }}">My Proposals</a>@endif
  </div>
  @if($user->isCreator())<a class="btn subtle" href="{{ url('/studio?='.$user->public_id) }}">Creator Studio</a>
  @elseif(in_array($user->role, ['customer','subscriber'], true))<a class="btn subtle" href="{{ route('creator.apply') }}">Become a creator</a>@endif
</div>
@endsection
