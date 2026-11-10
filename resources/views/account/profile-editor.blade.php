@extends('layouts.workspace')
@section('workspace',$workspace)
@section('title',$workspace === 'creator' ? 'Creator Profile' : 'Edit Profile')
@section('content')
@php
  $profileAction = $adminProfile ? route('dashboard.users.profile.update',$member) : ($workspace === 'creator' ? route('studio.profile.update') : route('account.profile.update'));
  $createItemRoute = $adminProfile ? route('dashboard.users.profile.items.store',$member) : route('account.profile.items.store');
  $createSectionRoute = $adminProfile ? route('dashboard.users.profile.sections.store',$member) : route('account.profile.sections.store');
  $orderSectionRoute = $adminProfile ? route('dashboard.users.profile.sections.order',$member) : route('account.profile.sections.order');
  $orderItemRoute = $adminProfile ? route('dashboard.users.profile.items.order',$member) : route('account.profile.items.order');
  $sectionUpdateRoute = fn($section) => $adminProfile ? route('dashboard.users.profile.sections.update',[$member,$section]) : route('account.profile.sections.update',$section);
  $sectionDestroyRoute = fn($section) => $adminProfile ? route('dashboard.users.profile.sections.destroy',[$member,$section]) : route('account.profile.sections.destroy',$section);
  $itemUpdateRoute = fn($item) => $adminProfile ? route('dashboard.users.profile.items.update',[$member,$item]) : route('account.profile.items.update',$item);
  $itemDestroyRoute = fn($item) => $adminProfile ? route('dashboard.users.profile.items.destroy',[$member,$item]) : route('account.profile.items.destroy',$item);
  $languageNames = ['Arabic','Bengali','Burmese','Cantonese','Chinese (Mandarin)','Dutch','English','Filipino','French','German','Gujarati','Hindi','Indonesian','Italian','Japanese','Javanese','Khmer','Korean','Lao','Malay','Nepali','Portuguese','Punjabi','Russian','Spanish','Swahili','Tamil','Telugu','Thai','Turkish','Ukrainian','Urdu','Vietnamese'];
  $proficiencies = ['Native','Fluent','Advanced','Professional','Intermediate','Good','Basic','Beginner'];
@endphp
<div class="container inner-page profile-v3 profile-v3-editor profile-v4-editor">
  <div class="page-intro profile-v3-edit-heading">
    <div><div class="eyebrow">{{ $adminProfile ? 'User Manager / Public presence' : 'My workspace / Profile settings' }}</div>
      <h1>{{ $workspace === 'creator' ? 'Creator Profile' : 'Edit Profile' }}</h1><p>Customize your public identity, profile sections and professional details.</p></div>
    <div class="profile-v3-actions">
      @if($adminProfile)<a class="btn subtle" href="{{ route('dashboard.users.show',$member) }}">Back to user</a>@else<a class="btn subtle" href="{{ route('account') }}">My account</a>@endif
      @if($member->isCreator() && ($creatorProfile?->is_public || $member->publishedListings()->exists()))
        <a class="btn subtle" href="{{ route('creators.show',$member->public_id) }}">View public profile</a>
      @endif
    </div>
  </div>
  <section class="panel profile-v3-edit-panel">
    <div class="panel-head"><div><h2>Profile identity &amp; introduction</h2><p>Preview your image immediately. Save to publish approved changes.</p></div></div>
    <form action="{{ $profileAction }}" method="post" enctype="multipart/form-data" class="profile-v3-edit-form" data-profile-identity-form>
      @csrf @method('PATCH')
      <div class="profile-v3-edit-grid">
        <div class="profile-v3-edit-avatar">
          <div class="profile-v4-avatar-dropzone" data-avatar-dropzone role="button" tabindex="0" aria-label="Select or drag a profile image here">
            <div class="profile-v3-avatar" data-avatar-preview>
              @if($personal?->avatar_path)
                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($personal->avatar_path) }}" alt="Profile image preview" width="240" height="240">
              @else
                <span aria-hidden="true">{{ \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($member->name,0,1)) }}</span>
              @endif
            </div>
            <div class="profile-v4-avatar-overlay" aria-hidden="true"><x-icon name="upload-cloud" size="26"/><strong>Drag &amp; drop</strong><span>or upload an image</span></div>
            <input id="profile-avatar" type="file" name="avatar" accept="image/jpeg,image/png,image/webp" data-avatar-file tabindex="-1" aria-label="Upload profile image">
          </div>
          <div class="profile-v4-avatar-caption"><strong>Profile photo</strong><small>Click or drop a JPEG, PNG or WebP (maximum 2 MB). Preview is immediate; save to keep it.</small></div>
          <p class="profile-v4-inline-status" data-avatar-status role="status" aria-live="polite" hidden></p>
          @if($personal?->avatar_path)
            <label class="profile-v3-checkbox"><input type="checkbox" name="remove_avatar" value="1" data-avatar-remove> Remove current image</label>
          @endif
        </div>
        <div class="profile-v3-edit-fields">
          <label>Full name <input name="name" required maxlength="120" minlength="2" value="{{ old('name',$member->name) }}" autocomplete="name"></label>
          <label>Public user ID <input value="{{ $member->public_id }}" readonly class="profile-v3-readonly"></label>
          <div class="profile-v4-location" data-profile-location data-current-location="{{ old('public_location',$personal?->public_location) }}">
            <input type="hidden" name="public_location" value="{{ old('public_location',$personal?->public_location) }}" data-location-value>
            <label for="profile-country">Country / Region
              <select id="profile-country" data-location-country aria-label="Select country or region"><option value="">Select country or region</option></select>
            </label>
            <label for="profile-city">City / Province
              <input id="profile-city" type="text" maxlength="70" data-location-city placeholder="City or province" autocomplete="address-level2">
            </label>
            <div class="profile-v4-location-tools">
              <button type="button" class="btn subtle compact" data-location-detect><x-icon name="map-pin" size="15"/> Detect my location</button>
              <small data-location-status role="status" aria-live="polite">Location is optional. Detection requests permission and uses OpenStreetMap for reverse geocoding.</small>
            </div>
          </div>
          <label>Availability <select name="availability">
            <option value="">Not specified</option>
            @foreach(['available'=>'Available','busy'=>'Busy','unavailable'=>'Unavailable'] as $value=>$label)
              <option value="{{ $value }}" @selected(old('availability',$personal?->availability)===$value)>{{ $label }}</option>
            @endforeach
          </select></label>
          @if($member->isCreator())
            <label class="profile-v4-full">Professional headline
              <textarea class="profile-v4-headline-input" name="headline" required minlength="8" maxlength="160" rows="2" placeholder="Describe your professional specialty">{{ old('headline',$creatorProfile?->headline) }}</textarea>
            </label>
            <label class="profile-v4-full">Professional biography
              <textarea name="biography" required minlength="80" maxlength="5000" rows="6" placeholder="Describe your experience, expertise and goals.">{{ old('biography',$creatorProfile?->biography) }}</textarea>
            </label>
            <label>Website (HTTPS) <input name="website_url" type="url" maxlength="400" value="{{ old('website_url',$creatorProfile?->website_url) }}" placeholder="https://example.com"></label>
            <label>Public visibility <select name="is_public" required>
              <option value="0" @selected((string)old('is_public',$creatorProfile?->is_public?'1':'0')==='0')>Private — do not publish profile details</option>
              <option value="1" @selected((string)old('is_public',$creatorProfile?->is_public?'1':'0')==='1')>Public — show approved profile details</option>
            </select></label>
          @else
            <p class="profile-v3-muted">Your account information remains private unless you become an eligible creator and explicitly publish it.</p>
          @endif
        </div>
      </div>
      <div class="profile-v3-form-footer">
        <small>Changing your public name can reset manual account review. Your email, credentials and private account records are never published.</small>
        <button class="btn primary" type="submit"><x-icon name="save" size="17"/> Save profile</button>
      </div>
    </form>
  </section>

  <div class="profile-v4-sections-bar">
    <div><h2>Customizable sections</h2><p>Drag sections or entries to reorder. Changes save automatically.</p></div>
    <button class="btn primary compact" type="button" data-profile-dialog-open="profile-add-section"><x-icon name="plus" size="17"/> Add section</button>
  </div>
  <div class="profile-v4-sections" data-profile-sortable="sections" data-profile-order-url="{{ $orderSectionRoute }}">
    @foreach($sections as $section)
      @php
        $kind=$section->section_key;
        $entries=$kind==='custom' ? $items->get('custom',collect())->where('profile_section_id',$section->id) : $items->get($kind,collect())->whereNull('profile_section_id');
      @endphp
      <section class="panel profile-v3-edit-panel profile-v4-section @if(!$section->is_visible) profile-v4-hidden-section @endif" data-profile-section-id="{{ $section->id }}" draggable="true">
        <div class="panel-head profile-v3-editor-head profile-v4-section-header">
          <button type="button" class="profile-v4-drag-handle" data-profile-sort-handle aria-label="Drag to reorder {{ $section->title }}. Use arrow keys to move."><x-icon name="grip-vertical" size="19"/></button>
          <div class="profile-v4-section-title"><h2>{{ $section->title }} @if(!$section->is_visible)<small>(Hidden)</small>@endif</h2>@if($section->description)<p>{{ $section->description }}</p>@endif</div>
          <div class="profile-v4-section-actions">
            <button type="button" class="btn primary compact" data-profile-dialog-open="profile-add-{{ $section->id }}"><x-icon name="plus" size="16"/> Add {{ $kind==='custom' ? 'entry' : $kind }}</button>
            <button type="button" class="profile-v4-menu-trigger" data-profile-menu-trigger aria-label="Actions for {{ $section->title }}" aria-haspopup="menu" aria-expanded="false"><x-icon name="more-vertical" size="18"/></button>
            <div class="profile-v4-action-menu" data-profile-menu role="menu" hidden>
              <button role="menuitem" type="button" data-profile-dialog-open="profile-edit-section-{{ $section->id }}"><x-icon name="pencil" size="15"/> Edit section</button>
              <button role="menuitem" type="button" data-profile-delete="{{ $sectionDestroyRoute($section) }}" data-profile-delete-label="{{ $section->title }}" data-profile-delete-kind="{{ $kind==='custom' ? 'custom section and all its entries' : 'section visibility' }}"><x-icon name="trash-2" size="15"/> {{ $kind==='custom' ? 'Delete section' : 'Hide section' }}</button>
            </div>
          </div>
        </div>
        <div class="profile-v3-item-grid profile-v4-item-grid" data-profile-sortable="items" data-profile-section="{{ $section->id }}" data-profile-order-url="{{ $orderItemRoute }}">
          @forelse($entries as $item)
            <div class="profile-v3-edit-item profile-v4-edit-item" data-profile-item-id="{{ $item->id }}" draggable="true">
              <button type="button" class="profile-v4-drag-handle" data-profile-sort-handle aria-label="Drag to reorder {{ $item->label }}. Use arrow keys to move."><x-icon name="grip-vertical" size="17"/></button>
              <div class="profile-v4-item-summary"><strong>{{ $item->label }}</strong>
                @if($item->detail)<small>{{ $item->detail }}</small>@endif
                @if($item->url)<small class="profile-v4-truncate">{{ $item->url }}</small>@endif
              </div>
              @if(!$item->is_visible)<span class="profile-v4-hidden-marker">Hidden</span>@endif
              <button type="button" class="profile-v4-menu-trigger" data-profile-menu-trigger aria-label="Actions for {{ $item->label }}" aria-haspopup="menu" aria-expanded="false"><x-icon name="more-vertical" size="18"/></button>
              <div class="profile-v4-action-menu" data-profile-menu role="menu" hidden>
                <button role="menuitem" type="button" data-profile-dialog-open="profile-item-{{ $item->id }}"><x-icon name="pencil" size="15"/> Edit</button>
                <button role="menuitem" type="button" data-profile-delete="{{ $itemDestroyRoute($item) }}" data-profile-delete-label="{{ $item->label }}" data-profile-delete-kind="entry"><x-icon name="trash-2" size="15"/> Delete</button>
              </div>
            </div>
          @empty
            <p class="profile-v4-empty">No entries yet. Use + to add one.</p>
          @endforelse
        </div>
        @foreach($entries as $item)
          <dialog class="profile-v3-dialog" id="profile-item-{{ $item->id }}" aria-label="Edit {{ $item->label }}">
            <div class="profile-v3-dialog-head"><h3>Edit {{ $section->title }} entry</h3><button type="button" class="btn subtle compact" data-profile-dialog-close>Close</button></div>
            <form method="post" action="{{ $itemUpdateRoute($item) }}" class="profile-v3-dialog-form">
              @csrf @method('PATCH')
              @if($kind==='language')
                <label>Language <select name="label" required>
                  @if(!in_array($item->label,$languageNames,true))<option value="{{ $item->label }}" selected>{{ $item->label }}</option>@endif
                  @foreach($languageNames as $name)<option value="{{ $name }}" @selected($item->label===$name)>{{ $name }}</option>@endforeach
                </select></label>
                <label>Proficiency <select name="detail">
                  <option value="">Not specified</option>
                  @if($item->detail && !in_array($item->detail,$proficiencies,true))<option value="{{ $item->detail }}" selected>{{ $item->detail }}</option>@endif
                  @foreach($proficiencies as $level)<option value="{{ $level }}" @selected($item->detail===$level)>{{ $level }}</option>@endforeach
                </select></label>
              @else
                <label>Title <input name="label" required maxlength="100" value="{{ $item->label }}"></label>
                @if($kind==='link')<label>HTTPS URL <input type="url" name="url" required maxlength="400" value="{{ $item->url }}"></label>
                @else<label>Details (optional) <input name="detail" maxlength="160" value="{{ $item->detail }}"></label>@endif
              @endif
              <label>Visibility <select name="is_visible"><option value="1" @selected($item->is_visible)>Visible</option><option value="0" @selected(!$item->is_visible)>Hidden</option></select></label>
              <button type="submit" class="btn primary">Save changes</button>
            </form>
          </dialog>
        @endforeach
        <dialog class="profile-v3-dialog" id="profile-add-{{ $section->id }}" aria-label="Add an entry to {{ $section->title }}">
          <div class="profile-v3-dialog-head"><h3>Add to {{ $section->title }}</h3><button type="button" class="btn subtle compact" data-profile-dialog-close>Close</button></div>
          <form method="post" action="{{ $createItemRoute }}" class="profile-v3-dialog-form">
            @csrf
            <input type="hidden" name="kind" value="{{ $kind }}">
            @if($kind==='custom')<input type="hidden" name="profile_section_id" value="{{ $section->id }}">@endif
            @if($kind==='language')
              <label>Language <select name="label" required><option value="">Select language</option>@foreach($languageNames as $name)<option value="{{ $name }}">{{ $name }}</option>@endforeach</select></label>
              <label>Proficiency <select name="detail"><option value="">Select proficiency</option>@foreach($proficiencies as $level)<option value="{{ $level }}">{{ $level }}</option>@endforeach</select></label>
            @else
              <label>Title <input name="label" required maxlength="100" placeholder="{{ $kind==='link' ? 'e.g. Portfolio' : 'e.g. Laravel development' }}"></label>
              @if($kind==='link')<label>HTTPS URL <input type="url" name="url" required maxlength="400" placeholder="https://example.com"></label>
              @else<label>Details (optional) <input name="detail" maxlength="160" placeholder="Optional description"></label>@endif
            @endif
            <input type="hidden" name="is_visible" value="1">
            <button type="submit" class="btn primary"><x-icon name="plus" size="16"/> Add entry</button>
          </form>
        </dialog>
        <dialog class="profile-v3-dialog" id="profile-edit-section-{{ $section->id }}" aria-label="Edit section {{ $section->title }}">
          <div class="profile-v3-dialog-head"><h3>Edit section</h3><button type="button" class="btn subtle compact" data-profile-dialog-close>Close</button></div>
          <form method="post" action="{{ $sectionUpdateRoute($section) }}" class="profile-v3-dialog-form">
            @csrf @method('PATCH')
            <label>Section title <input name="title" required minlength="2" maxlength="100" value="{{ $section->title }}"></label>
            <label>Short description <input name="description" maxlength="200" value="{{ $section->description }}"></label>
            <label>Visibility <select name="is_visible"><option value="1" @selected($section->is_visible)>Visible</option><option value="0" @selected(!$section->is_visible)>Hidden</option></select></label>
            <button type="submit" class="btn primary">Save section</button>
          </form>
        </dialog>
      </section>
    @endforeach
  </div>
  <dialog class="profile-v3-dialog" id="profile-add-section" aria-label="Add a custom profile section">
    <div class="profile-v3-dialog-head"><h3>Add custom section</h3><button type="button" class="btn subtle compact" data-profile-dialog-close>Close</button></div>
    <form method="post" action="{{ $createSectionRoute }}" class="profile-v3-dialog-form">
      @csrf
      <label>Section title <input name="title" required minlength="2" maxlength="100" placeholder="e.g. Certifications"></label>
      <label>Short description (optional) <input name="description" maxlength="200" placeholder="What this section contains"></label>
      <p class="profile-v3-muted">You can add up to eight custom sections and reorder them alongside the standard sections.</p>
      <button type="submit" class="btn primary"><x-icon name="plus" size="16"/> Add section</button>
    </form>
  </dialog>
  <dialog class="profile-v3-dialog" id="profile-confirm-delete" aria-label="Confirm profile deletion">
    <div class="profile-v3-dialog-head"><h3>Confirm action</h3><button type="button" class="btn subtle compact" data-profile-dialog-close>Cancel</button></div>
    <p class="profile-v4-confirm-text" data-profile-confirm-text></p>
    <form method="post" action="" data-profile-confirm-form class="profile-v4-confirm-form">
      @csrf @method('DELETE')
      <button type="submit" class="btn primary">Confirm</button>
    </form>
  </dialog>

  @if($member->isCreator())
    <section class="panel profile-v3-edit-panel">
      <div class="panel-head"><h2>Creator Badges <span class="count">{{ $awards->count() }}</span></h2></div>
      <div class="profile-v3-badge-editor">
        <x-creator-badges :badges="$awards"/>
        <p class="profile-v3-muted">Badges are awarded by platform rules or administrators. They cannot be self-awarded or modified here.</p>
      </div>
    </section>
  @endif
</div>
@endsection
