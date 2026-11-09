@extends('layouts.dashboard')
@section('title','Badge Settings')
@section('content')
<div class="container inner-page badge-manager badge-settings-page">
  <header class="page-intro badge-manager-heading">
    <div><div class="eyebrow">Creator Badges / Appearance &amp; Catalog</div>
      <h1>Badge Settings</h1>
      <p>Customize badge display, names, descriptions, icons, colors and eligibility rules.</p>
    </div>
    <a href="{{ route('dashboard.badges.index') }}" class="btn subtle"><x-icon name="chevron-left" size="16"/> Badge Manager</a>
  </header>
  <section class="panel">
    <div class="panel-head"><h2><x-icon name="settings" size="19"/> Display preferences</h2></div>
    <form method="post" action="{{ route('dashboard.badges.display.update') }}" class="badge-manager-settings-form">
      @csrf @method('PATCH')
      <div class="badge-manager-input-grid">
        <label>Show badges as
          <select name="display_mode" required>
            @foreach(\App\Support\CreatorBadgeDisplay::MODES as $value=>$text)
              <option value="{{ $value }}" @selected($display->display_mode===$value)>{{ $text }}</option>
            @endforeach
          </select>
          <small>Badge shows the icon only. Badge + Name shows the medal with its name.</small>
        </label>
        <label>Badge size
          <select name="badge_size" required>
            @foreach(\App\Support\CreatorBadgeDisplay::SIZES as $value=>$text)
              <option value="{{ $value }}" @selected($display->badge_size===$value)>{{ $text }}</option>
            @endforeach
          </select>
          <small>Adjusts medal artwork and label spacing on public creator pages.</small>
        </label>
        <label>Descriptions on hover/focus
          <select name="tooltips_enabled" required>
            <option value="1" @selected($display->tooltips_enabled)>Enabled</option>
            <option value="0" @selected(!$display->tooltips_enabled)>Disabled</option>
          </select>
          <small>Keyboard users can focus badges for full descriptions when enabled.</small>
        </label>
      </div>
      <div class="badge-manager-settings-actions">
        <x-creator-badges :badges="$badges->where('enabled',true)->take(3)" variant="compact"/>
        <button type="submit" class="btn primary"><x-icon name="save" size="17"/> Save appearance</button>
      </div>
    </form>
  </section>

  <section class="panel">
    <div class="panel-head badge-manager-panel-head">
      <div><h2>Badge catalog <span class="count">{{ $badges->count() }}</span></h2>
        <small>Built-in designs are protected; custom badges may be created and deleted when unused.</small></div>
      <button type="button" class="btn primary compact" data-badge-dialog-open="badge-create-dialog"><x-icon name="plus" size="17"/> Add custom badge</button>
    </div>
    <div class="badge-manager-catalog-grid">
      @foreach($badges as $badge)
      <article class="badge-manager-definition-card">
        <div class="badge-manager-definition-head">
          <div class="badge-manager-definition-preview">
            @if(is_file(public_path('images/badges/'.$badge->slug.'.svg')))
              <img src="{{ asset('images/badges/'.$badge->slug.'.svg') }}" alt="" width="62" height="62" loading="lazy">
            @else
              <span class="badge-manager-custom-medal"><x-icon :name="$badge->icon" size="28"/></span>
            @endif
          </div>
          <div><strong>{{ $badge->name }}</strong>
            <small>{{ $badge->slug }} · {{ $badge->grants_count }} manual grants</small>
            <span class="user-manager-status user-manager-status--{{ $badge->enabled?'active':'suspended' }}">{{ $badge->enabled?'Enabled':'Disabled' }}</span>
          </div>
        </div>
        <form method="post" action="{{ route('dashboard.badges.update',$badge) }}" class="badge-manager-definition-form">
          @csrf @method('PATCH')
          <label>Badge name<input type="text" name="name" value="{{ $badge->name }}" maxlength="90" required></label>
          <label>Hover description<textarea name="description" rows="3" maxlength="1000" required>{{ $badge->description }}</textarea></label>
          <div class="badge-manager-input-grid">
            <label>Icon
              <select name="icon">
              @foreach(\App\Http\Controllers\Dashboard\CreatorBadgeController::ICONS as $option)
                <option value="{{ $option }}" @selected($badge->icon===$option)>{{ ucwords(str_replace('-',' ',$option)) }}</option>
              @endforeach
              </select>
            </label>
            <label>Color
              <select name="tone">
              @foreach(\App\Http\Controllers\Dashboard\CreatorBadgeController::TONES as $option)
                <option value="{{ $option }}" @selected($badge->tone===$option)>{{ ucfirst($option) }}</option>
              @endforeach
              </select>
            </label>
            <label>Grant mode
              <select name="grant_mode">
                <option value="manual" @selected($badge->grant_mode==='manual')>Manual (Admin / Staff)</option>
                <option value="auto" @selected($badge->grant_mode==='auto') @disabled(!in_array($badge->slug,\App\Http\Controllers\Dashboard\CreatorBadgeController::AUTO_SLUGS,true))>Auto (verified metrics)</option>
              </select>
            </label>
            <label>Status
              <select name="enabled">
                <option value="1" @selected($badge->enabled)>Enabled</option>
                <option value="0" @selected(!$badge->enabled)>Disabled</option>
              </select>
            </label>
            <label>Sort order<input type="number" name="sort_order" min="1" max="999" value="{{ $badge->sort_order }}" required></label>
          </div>
          <button class="btn primary compact" type="submit">Save badge</button>
        </form>
        @unless(in_array($badge->slug,[
          'top-rated','rising-talent','expert-vetted','fast-responder','identity-verified',
          'job-success-100','repeat-clients','top-rated-plus','on-time-delivery','pro-verified',
          'super-seller-level-two','top-collaborator','skill-certified','high-earner',
          'community-leader','vip-client-favorite'
        ],true))
          <form method="post" action="{{ route('dashboard.badges.destroy',$badge) }}" class="badge-manager-delete-form">
            @csrf @method('DELETE')
            <label>Type DELETE to remove this unused badge
              <input name="confirmation" type="text" pattern="DELETE" placeholder="DELETE" required autocomplete="off">
            </label>
            <button class="btn subtle compact" type="submit" @disabled($badge->grants_count>0)>Delete custom badge</button>
            @if($badge->grants_count>0)<small>Revoke existing awards before deleting; disable to hide immediately.</small>@endif
          </form>
        @else
          <small class="hint">Original Bambodia medal design protected against deletion.</small>
        @endunless
      </article>
      @endforeach
    </div>
  </section>

  <dialog id="badge-create-dialog" class="config-dialog badge-manager-dialog" aria-labelledby="badge-create-title">
    <div class="badge-manager-dialog-top">
      <div><div class="eyebrow">Badge catalog</div><h2 id="badge-create-title">New custom badge</h2></div>
      <button class="icon-button" type="button" data-badge-dialog-close aria-label="Close"><x-icon name="x" size="19"/></button>
    </div>
    <p>Custom badges display an approved SVG icon fallback until you supply an official artwork asset.</p>
    <form method="post" action="{{ route('dashboard.badges.store') }}" class="badge-manager-global-form">
      @csrf
      <label>Badge slug<input name="slug" required maxlength="70" pattern="[a-z][a-z0-9-]*" placeholder="e.g. trusted-partner"></label>
      <label>Badge name<input name="name" required maxlength="90" placeholder="Trusted Partner"></label>
      <label>Hover description<textarea name="description" rows="3" required maxlength="1000"></textarea></label>
      <div class="badge-manager-input-grid">
        <label>Icon<select name="icon">
          @foreach(\App\Http\Controllers\Dashboard\CreatorBadgeController::ICONS as $icon)
            <option value="{{ $icon }}">{{ ucwords(str_replace('-',' ',$icon)) }}</option>
          @endforeach
        </select></label>
        <label>Color<select name="tone">
          @foreach(\App\Http\Controllers\Dashboard\CreatorBadgeController::TONES as $tone)
            <option value="{{ $tone }}">{{ ucfirst($tone) }}</option>
          @endforeach
        </select></label>
        <label>Sort order<input type="number" name="sort_order" min="1" max="999" value="50" required></label>
      </div>
      <div class="dialog-actions">
        <button class="btn subtle" type="button" data-badge-dialog-close>Cancel</button>
        <button class="btn primary" type="submit">Create badge</button>
      </div>
    </form>
  </dialog>
</div>
@endsection
