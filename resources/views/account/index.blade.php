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
  @if($user->isCreator())<a class="btn subtle" href="{{ url('/studio?='.$user->public_id) }}">Creator studio</a>@endif
</div>
@endsection
