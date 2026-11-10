@extends('layouts.workspace')
@section('workspace',$workspace)
@section('title','Edit Profile')
@section('content')
@php
  $profileAction = $adminProfile ? route('dashboard.users.profile.update',$member) : ($workspace === 'creator' ? route('studio.profile.update') : route('account.profile.update'));
  $createItemRoute = $adminProfile ? route('dashboard.users.profile.items.store',$member) : route('account.profile.items.store');
  $sectionLabels=['expertise'=>'Expertise & Services','language'=>'Languages','link'=>'Contact & Links'];
  $sectionSubtitles=[
    'expertise'=>'Show the services and professional skills you offer.',
    'language'=>'List languages and proficiency levels.',
    'link'=>'Add HTTPS links to your professional portfolio and social pages.',
  ];
@endphp
<div class="container inner-page profile-v3 profile-v3-editor">
  <div class="page-intro profile-v3-edit-heading">
    <div><div class="eyebrow">{{ $adminProfile ? 'User Manager / Public presence' : 'My workspace / Profile settings' }}</div>
      <h1>Edit Profile</h1><p>Customize your account profile. Only explicitly published creator information can appear publicly.</p></div>
    <div class="profile-v3-actions">
      @if($adminProfile)<a class="btn subtle" href="{{ route('dashboard.users.show',$member) }}">Back to user</a>@else<a class="btn subtle" href="{{ route('account') }}">My account</a>@endif
      @if($member->isCreator() && ($creatorProfile?->is_public || $member->publishedListings()->exists()))
        <a class="btn subtle" href="{{ route('creators.show',$member->public_id) }}">View public profile</a>
      @endif
    </div>
  </div>
  <section class="panel profile-v3-edit-panel">
    <div class="panel-head"><div><h2>Profile identity &amp; introduction</h2><p>Use your own profile image and authentic professional information.</p></div></div>
    <form action="{{ $profileAction }}" method="post" enctype="multipart/form-data" class="profile-v3-edit-form">
      @csrf @method('PATCH')
      <div class="profile-v3-edit-grid">
        <div class="profile-v3-edit-avatar">
          <div class="profile-v3-avatar">
            @if($personal?->avatar_path)
              <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($personal->avatar_path) }}" alt="Current profile image" width="240" height="240">
            @else
              <span aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($member->name,0,1)) }}</span>
            @endif
          </div>
          <label for="profile-avatar">Profile image</label>
          <input id="profile-avatar" type="file" name="avatar" accept="image/jpeg,image/png,image/webp">
          <small>JPEG, PNG or WebP. Maximum 2 MB. Only shown publicly when the creator profile is published.</small>
          @if($personal?->avatar_path)
            <label class="profile-v3-checkbox"><input type="checkbox" name="remove_avatar" value="1"> Remove current image</label>
          @endif
        </div>
        <div class="profile-v3-edit-fields">
          <label>Full name <input name="name" required maxlength="120" minlength="2" value="{{ old('name',$member->name) }}"></label>
          <label>Public user ID <input value="{{ $member->public_id }}" readonly class="profile-v3-readonly"></label>
          <label>Location (optional) <input name="public_location" maxlength="120" value="{{ old('public_location',$personal?->public_location) }}" placeholder="City, Country"></label>
          <label>Availability <select name="availability">
            <option value="">Not specified</option>
            @foreach(['available'=>'Available','busy'=>'Busy','unavailable'=>'Unavailable'] as $value=>$label)
              <option value="{{ $value }}" @selected(old('availability',$personal?->availability)===$value)>{{ $label }}</option>
            @endforeach
          </select></label>
          @if($member->isCreator())
            <label>Professional headline <input name="headline" required minlength="8" maxlength="160" value="{{ old('headline',$creatorProfile?->headline) }}" placeholder="Describe your professional specialty"></label>
            <label>Professional biography <textarea name="biography" required minlength="80" maxlength="5000" rows="7" placeholder="Describe your experience, expertise and goals.">{{ old('biography',$creatorProfile?->biography) }}</textarea></label>
            <label>Website (HTTPS) <input name="website_url" type="url" maxlength="400" value="{{ old('website_url',$creatorProfile?->website_url) }}" placeholder="https://example.com"></label>
            <label>Public visibility <select name="is_public" required>
              <option value="0" @selected((string)old('is_public',$creatorProfile?->is_public?'1':'0')==='0')>Private — hide personal biography, image and links</option>
              <option value="1" @selected((string)old('is_public',$creatorProfile?->is_public?'1':'0')==='1')>Public — show approved profile details</option>
            </select></label>
          @else
            <p class="profile-v3-muted">Personal profile content stays private unless this account later becomes an eligible creator and chooses to publish it.</p>
          @endif
        </div>
      </div>
      <div class="profile-v3-form-footer"><small>Changing your public name can reset previous manual account review. Your email, credentials, and private account records are never published here.</small>
        <button class="btn primary" type="submit"><x-icon name="save" size="17"/> Save profile</button></div>
    </form>
  </section>

  @foreach($sectionLabels as $kind=>$heading)
    <section class="panel profile-v3-edit-panel">
      <div class="panel-head profile-v3-editor-head">
        <div><h2>{{ $heading }}</h2><p>{{ $sectionSubtitles[$kind] }}</p></div>
        <button type="button" class="btn primary compact" data-profile-dialog-open="profile-add-{{ $kind }}"><x-icon name="plus" size="16"/> Add {{ $kind }}</button>
      </div>
      <div class="profile-v3-item-grid">
        @forelse($items->get($kind,collect()) as $item)
          <div class="profile-v3-edit-item">
            <div><strong>{{ $item->label }}</strong>
              @if($item->detail)<small>{{ $item->detail }}</small>@endif
              @if($item->url)<small>{{ $item->url }}</small>@endif
              <span class="profile-v3-item-status">{{ $item->is_visible ? 'Visible when published' : 'Hidden' }}</span>
            </div>
            <div class="profile-v3-item-actions">
              <button type="button" class="btn subtle compact" data-profile-dialog-open="profile-item-{{ $item->id }}"><x-icon name="pencil" size="14"/> Edit</button>
              <form method="post" action="{{ $adminProfile ? route('dashboard.users.profile.items.destroy',[$member,$item]) : route('account.profile.items.destroy',$item) }}" onsubmit="return confirm('Delete this profile item?')">
                @csrf @method('DELETE')
                <button class="btn subtle compact" type="submit"><x-icon name="trash-2" size="14"/> Delete</button>
              </form>
            </div>
          </div>
          <dialog class="profile-v3-dialog" id="profile-item-{{ $item->id }}" aria-label="Edit {{ $item->label }}">
            <div class="profile-v3-dialog-head"><h3>Edit {{ $heading }}</h3><button type="button" class="btn subtle compact" data-profile-dialog-close>Close</button></div>
            <form method="post" action="{{ $adminProfile ? route('dashboard.users.profile.items.update',[$member,$item]) : route('account.profile.items.update',$item) }}" class="profile-v3-dialog-form">
              @csrf @method('PATCH')
              <label>Title <input name="label" required maxlength="100" value="{{ $item->label }}"></label>
              @if($kind==='link')<label>HTTPS URL <input type="url" name="url" required maxlength="400" value="{{ $item->url }}"></label>
              @else<label>Details / Proficiency (optional) <input name="detail" maxlength="160" value="{{ $item->detail }}"></label>@endif
              <label>Display order <input name="sort_order" type="number" min="0" max="999" value="{{ $item->sort_order }}"></label>
              <label>Visibility <select name="is_visible"><option value="1" @selected($item->is_visible)>Visible</option><option value="0" @selected(!$item->is_visible)>Hidden</option></select></label>
              <button type="submit" class="btn primary">Save changes</button>
            </form>
          </dialog>
        @empty
          <p class="profile-v3-muted">Nothing added yet. Use Add {{ $kind }} to create an entry.</p>
        @endforelse
      </div>
      <dialog class="profile-v3-dialog" id="profile-add-{{ $kind }}" aria-label="Add {{ $heading }}">
        <div class="profile-v3-dialog-head"><h3>Add {{ $heading }}</h3><button type="button" class="btn subtle compact" data-profile-dialog-close>Close</button></div>
        <form method="post" action="{{ $createItemRoute }}" class="profile-v3-dialog-form">
          @csrf
          <input type="hidden" name="kind" value="{{ $kind }}">
          <label>Title <input name="label" required maxlength="100" placeholder="{{ $kind === 'language' ? 'e.g. Khmer' : ($kind === 'link' ? 'e.g. Portfolio' : 'e.g. Laravel development') }}"></label>
          @if($kind==='link')<label>HTTPS URL <input type="url" name="url" required maxlength="400" placeholder="https://example.com"></label>
          @else<label>Details / Proficiency (optional) <input name="detail" maxlength="160" placeholder="Optional description"></label>@endif
          <label>Display order <input name="sort_order" type="number" min="0" max="999" value="0"></label>
          <label>Visibility <select name="is_visible"><option value="1">Visible</option><option value="0">Hidden</option></select></label>
          <button type="submit" class="btn primary">Add entry</button>
        </form>
      </dialog>
    </section>
  @endforeach

  @if($member->isCreator())
    <section class="panel profile-v3-edit-panel">
      <div class="panel-head"><h2>Creator Badges <span class="count">{{ $awards->count() }}</span></h2></div>
      <div class="profile-v3-badge-editor">
        <x-creator-badges :badges="$awards"/>
        <p class="profile-v3-muted">These achievements are managed through platform rules and administrator approvals. They cannot be self-awarded or modified in this profile editor.</p>
      </div>
    </section>
  @endif
</div>
@endsection
