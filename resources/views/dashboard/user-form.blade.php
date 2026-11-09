@extends('layouts.dashboard')
@section('title',$isCreate?'Create User':'Edit User')
@section('content')
@php
  $action=$isCreate ? route('dashboard.users.store') : route('dashboard.users.update',$member);
  $protectedOwner=!$isCreate && $member->role==='super_admin' && strcasecmp($member->email,\App\Support\CreatorBadgeAwards::OWNER_EMAIL)===0;
@endphp
<div class="container inner-page user-manager-v2">
  <div class="page-intro user-manager-header"><div>
    <div class="eyebrow">User Manager / {{ $isCreate?'New account':'Edit account' }}</div>
    <h1>{{ $isCreate?'Create new user':'Edit '.$member->name }}</h1>
    <p>Account details and internal profile fields are protected. Changes to access are recorded in the audit log.</p>
  </div><a href="{{ $isCreate?route('dashboard.users'):route('dashboard.users.show',$member) }}" class="btn subtle"><x-icon name="arrow-left" size="16"/> Back</a></div>

  <form method="post" action="{{ $action }}" class="user-manager-form">
    @csrf
    @unless($isCreate) @method('PATCH') @endunless
    <section class="panel">
      <div class="panel-head"><h2>Account identity</h2></div>
      <div class="user-manager-field-grid">
        <label>Full name <input name="name" required maxlength="120" minlength="2" value="{{ old('name',$member->name) }}" autocomplete="off"></label>
        <label>Username <input name="username" maxlength="60" minlength="3" value="{{ old('username',$member->username) }}" placeholder="name.example" autocomplete="off" pattern="[A-Za-z][A-Za-z0-9_.-]{2,59}"><small>Unique; begins with a letter</small></label>
        <label>Email <input name="email" type="email" required maxlength="255" value="{{ old('email',$member->email) }}" @readonly($protectedOwner)><small>{{ $protectedOwner?'The designated owner email is protected.':'Changing email resets email verification and active sessions.' }}</small></label>
        <label>Language <select name="locale" required>
          @foreach(['en'=>'English','km'=>'Khmer','zh'=>'Chinese','ko'=>'Korean','ja'=>'Japanese','th'=>'Thai','vi'=>'Vietnamese','de'=>'German'] as $code=>$name)
          <option value="{{ $code }}" @selected(old('locale',$member->locale??'en')===$code)>{{ $name }}</option>
          @endforeach
        </select></label>
      </div>
    </section>
    <section class="panel">
      <div class="panel-head"><h2>Personal profile <span class="user-manager-private">Internal only</span></h2></div>
      <div class="user-manager-field-grid">
        <label>Date of birth <input type="date" name="date_of_birth" value="{{ old('date_of_birth',$profile->date_of_birth?->format('Y-m-d')) }}" max="{{ now()->subDay()->toDateString() }}"></label>
        <label>Nationality <input name="nationality" maxlength="80" value="{{ old('nationality',$profile->nationality) }}" placeholder="e.g. Cambodian"></label>
        <label class="user-manager-full">Biography / Notes <textarea name="bio" rows="5" maxlength="3000" placeholder="Optional internal profile biography">{{ old('bio',$profile->bio) }}</textarea><small>Not published on the creator marketplace profile.</small></label>
      </div>
    </section>
    <section class="panel">
      <div class="panel-head"><h2>Roles &amp; permissions</h2></div>
      <div class="user-manager-field-grid">
        <label>Account role <select name="role" required>
          @foreach(\App\Models\User::ROLES as $role)
          <option value="{{ $role }}" @selected(old('role',$member->role)===$role) @disabled($protectedOwner && $role!=='super_admin')>{{ ucwords(str_replace('_',' ',$role)) }}</option>
          @endforeach
        </select></label>
        @unless($isCreate)
        <label>Account status <select name="account_status" required>
          @foreach(['active','suspended','archived'] as $option)
          <option value="{{ $option }}" @selected(old('account_status',$member->account_status)===$option) @disabled($protectedOwner && $option!=='active')>{{ ucfirst($option) }}</option>
          @endforeach
        </select><small>Suspended/archived accounts cannot access the website or API.</small></label>
        @endunless
      </div>
      <div class="user-manager-permissions">
        <h3>Individual restrictions</h3>
        <p class="hint">A role sets the maximum access. The settings below may restrict access to the connected workflows; they cannot grant more access than the role allows. Superadmin access is not restricted.</p>
        @foreach(\App\Support\UserManagementAccess::CAPABILITIES as $key=>$label)
        <label class="user-manager-permission">
          <span><strong>{{ $label }}</strong><small>{{ $key }}</small></span>
          <select name="permissions[{{ $key }}]" aria-label="Access to {{ $label }}">
            <option value="1" @selected((string)old('permissions.'.$key,($permissions[$key]['allowed']??true)?'1':'0')==='1')>Allowed by role</option>
            <option value="0" @selected((string)old('permissions.'.$key,($permissions[$key]['allowed']??true)?'1':'0')==='0')>Restrict access</option>
          </select>
        </label>
        @endforeach
      </div>
    </section>
    <section class="panel">
      <div class="panel-head"><h2>{{ $isCreate?'Initial password':'Reset password' }}</h2></div>
      <div class="user-manager-field-grid">
        <label>{{ $isCreate?'Password':'New password (optional)' }} <input type="password" name="password" minlength="12" maxlength="128" autocomplete="new-password" @required($isCreate)><small>At least 12 characters. Never send passwords in public support messages.</small></label>
        <label>Confirm password <input type="password" name="password_confirmation" minlength="12" maxlength="128" autocomplete="new-password" @required($isCreate)></label>
      </div>
    </section>
    <div class="user-manager-form-actions">
      <a href="{{ $isCreate?route('dashboard.users'):route('dashboard.users.show',$member) }}" class="btn subtle">Cancel</a>
      <button class="btn primary" type="submit"><x-icon name="save" size="17"/> {{ $isCreate?'Create user':'Save user changes' }}</button>
    </div>
  </form>
</div>
@endsection