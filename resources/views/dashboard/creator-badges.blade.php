@extends('layouts.dashboard')
@section('title','Creator Badges')
@section('content')
<div class="container inner-page badge-manager">
  <header class="page-intro badge-manager-heading">
    <div>
      <div class="eyebrow">Administration / Trust &amp; Reputation</div>
      <h1>Creator Badges</h1>
      <p>Review awards, manage individual badges and keep the 16 original medals consistent across light and dark mode.</p>
    </div>
    <div class="badge-manager-heading-actions">
      @if(in_array(auth()->user()->role,['super_admin','admin'],true))
        <a class="icon-button badge-manager-settings" href="{{ route('dashboard.badges.settings') }}"
           title="Badge catalog settings" aria-label="Open Badge catalog settings"><x-icon name="settings" size="20"/></a>
      @endif
      @if($canGrant && auth()->user()->role==='super_admin')
        <button type="button" class="btn subtle" data-badge-dialog-open="badge-global-grant">
          <x-icon name="users" size="17"/> Grant all to users
        </button>
      @endif
    </div>
  </header>

  {{-- Section 1: Gallery-only badge catalog. Configuration opens in a centered dialog. --}}
  <section class="panel badge-catalog-restored badge-catalog-gallery" id="badge-catalog" aria-labelledby="badge-catalog-title">
    <div class="panel-head badge-catalog-restored-head">
      <div>
        <h2 id="badge-catalog-title">Badge catalog <span class="count">{{ $badges->count() }} badges</span></h2>
        <small>Explore your premium medals and open the three-dot menu to manage each badge.</small>
      </div>
      @if($canEdit)
        <button type="button" class="btn primary compact" data-badge-dialog-open="badge-create-dialog">
          <x-icon name="plus" size="16"/> Add custom badge
        </button>
      @endif
    </div>
    <div class="creator-badge-admin-grid badge-catalog-restored-grid badge-catalog-gallery-grid">
      @foreach($badges as $badge)
        <article class="creator-badge-admin-card badge-catalog-restored-card badge-catalog-gallery-card" data-badge-card="{{ $badge->slug }}">
          <div class="badge-catalog-gallery-card-head">
            <span class="user-manager-status user-manager-status--{{ $badge->enabled?'active':'suspended' }}">
              {{ $badge->enabled?'Enabled':'Disabled' }}
            </span>
            @if($canEdit)
              <button type="button" class="badge-catalog-action-trigger"
                data-badge-dialog-open="badge-editor-{{ $badge->id }}"
                aria-haspopup="dialog"
                aria-controls="badge-editor-{{ $badge->id }}"
                aria-label="Manage {{ $badge->name }} badge"
                title="Manage {{ $badge->name }}">
                <x-icon name="ellipsis-vertical" size="20"/>
              </button>
            @else
              <span class="badge-catalog-readonly-label" title="Badge details"><x-icon name="shield-check" size="16"/></span>
            @endif
          </div>
          <div class="badge-catalog-gallery-center">
            <div class="badge-catalog-gallery-art" aria-hidden="true">
              @if(is_file(public_path('images/badges/'.$badge->slug.'.svg')))
                <img src="{{ asset('images/badges/'.$badge->slug.'.svg') }}" alt="" width="126" height="126" loading="lazy" decoding="async" draggable="false">
              @else
                <span class="badge-catalog-gallery-fallback badge-catalog-gallery-fallback--{{ $badge->tone }}">
                  <x-icon :name="$badge->icon" size="54"/>
                </span>
              @endif
            </div>
            <h3 class="badge-catalog-gallery-name">{{ $badge->name }}</h3>
            <p class="badge-catalog-gallery-description">{{ $badge->description }}</p>
          </div>
          <div class="badge-catalog-gallery-footer">
            <span><x-icon name="award" size="14"/> {{ $badge->grants_count }} {{ $badge->grants_count===1?'grant':'grants' }}</span>
            <span><x-icon :name="$badge->grant_mode==='auto'?'zap':'shield-check'" size="14"/> {{ $badge->grant_mode==='auto'?'Automatic':'Manual' }}</span>
          </div>
        </article>
      @endforeach
    </div>
  </section>

  {{-- Authorized configuration dialogs are outside the gallery grid. --}}
  @if($canEdit)
    @foreach($badges as $badge)
      @php
        $autoSupported=in_array($badge->slug,\App\Http\Controllers\Dashboard\CreatorBadgeController::AUTO_SLUGS,true);
        $rules=$badge->auto_rules??[];
        $builtIn=in_array($badge->slug,[
          'top-rated','rising-talent','expert-vetted','fast-responder',
          'identity-verified','job-success-100','repeat-clients','top-rated-plus',
          'on-time-delivery','pro-verified','super-seller-level-two','top-collaborator',
          'skill-certified','high-earner','community-leader','vip-client-favorite'
        ],true);
      @endphp
      <dialog class="config-dialog badge-manager-dialog badge-catalog-editor-dialog"
        id="badge-editor-{{ $badge->id }}"
        aria-labelledby="badge-editor-title-{{ $badge->id }}"
        aria-describedby="badge-editor-description-{{ $badge->id }}">
        <div class="badge-manager-dialog-top badge-catalog-editor-top">
          <div>
            <div class="eyebrow">Creator badges / Manage badge</div>
            <h2 id="badge-editor-title-{{ $badge->id }}">{{ $badge->name }}</h2>
            <p id="badge-editor-description-{{ $badge->id }}">Preview, edit settings, manage eligibility, or duplicate this badge.</p>
          </div>
          <button type="button" class="icon-button" data-badge-dialog-close aria-label="Close {{ $badge->name }} editor">
            <x-icon name="x" size="19"/>
          </button>
        </div>
        <div class="badge-catalog-editor-summary">
          <div class="badge-catalog-editor-medal" aria-hidden="true">
            @if(is_file(public_path('images/badges/'.$badge->slug.'.svg')))
              <img src="{{ asset('images/badges/'.$badge->slug.'.svg') }}" alt="" width="92" height="92" loading="lazy">
            @else
              <span class="badge-catalog-gallery-fallback badge-catalog-gallery-fallback--{{ $badge->tone }}">
                <x-icon :name="$badge->icon" size="44"/>
              </span>
            @endif
          </div>
          <div class="badge-catalog-editor-preview-text">
            <strong>Badge preview</strong>
            <p>{{ $badge->description }}</p>
            <div class="badge-catalog-editor-facts">
              <span class="user-manager-status user-manager-status--{{ $badge->enabled?'active':'suspended' }}">{{ $badge->enabled?'Enabled':'Disabled' }}</span>
              <span>{{ $badge->grant_mode==='auto'?'Automatic':'Manual' }} granting</span>
              <span>{{ $badge->grants_count }} manual grants</span>
            </div>
          </div>
        </div>
        <section class="badge-catalog-editor-section" aria-label="Edit badge">
          <h3><x-icon name="pencil" size="17"/> Edit badge &amp; conditions</h3>
          <p class="hint">Save changes here. Built-in metallic medal artwork remains protected.</p>
            <form method="post" action="{{ route('dashboard.badges.update',$badge) }}" class="badge-catalog-card-form">
              @csrf @method('PATCH')
              <label>Badge name
                <input name="name" maxlength="90" required value="{{ $badge->name }}">
              </label>
              <label>Hover description
                <textarea name="description" rows="3" maxlength="1000" required>{{ $badge->description }}</textarea>
              </label>
              <label>Eligibility / manual review conditions
                <textarea name="eligibility_criteria" rows="2" maxlength="1000" placeholder="Evidence required before manually granting this badge">{{ $badge->eligibility_criteria??$badge->description }}</textarea>
              </label>
              <div class="creator-badge-admin-options">
                <label>Grant mode
                  <select name="grant_mode">
                    <option value="manual" @selected($badge->grant_mode==='manual')>Manual — Admin / Staff</option>
                    <option value="auto" @selected($badge->grant_mode==='auto') @disabled(!$autoSupported)>Auto — verified metrics</option>
                  </select>
                </label>
                <label>Status
                  <select name="enabled">
                    <option value="1" @selected($badge->enabled)>Enabled</option>
                    <option value="0" @selected(!$badge->enabled)>Disabled</option>
                  </select>
                </label>
              </div>

              @if($autoSupported)
                <details class="badge-catalog-auto-conditions">
                  <summary><x-icon name="sliders-horizontal" size="15"/> Automatic eligibility conditions</summary>
                  <p>Only completed platform contracts and verified milestones count. Changes may update automatically earned badges.</p>
                  <div class="badge-catalog-condition-grid">
                    @if($badge->slug==='rising-talent')
                      <label>Minimum completed contracts
                        <input type="number" name="auto_rules[min_completed]" min="1" max="1000" required value="{{ $rules['min_completed']??1 }}">
                      </label>
                      <label>Maximum completed contracts
                        <input type="number" name="auto_rules[max_completed]" min="1" max="1000" required value="{{ $rules['max_completed']??5 }}">
                      </label>
                      <label>Maximum account age (days)
                        <input type="number" name="auto_rules[max_account_age_days]" min="30" max="1095" required value="{{ $rules['max_account_age_days']??365 }}">
                      </label>
                    @elseif($badge->slug==='repeat-clients')
                      <label>Minimum completed contracts with the same client
                        <input type="number" name="auto_rules[min_repeat_contracts]" min="2" max="1000" required value="{{ $rules['min_repeat_contracts']??2 }}">
                      </label>
                    @elseif($badge->slug==='on-time-delivery')
                      <label>Minimum completed contracts
                        <input type="number" name="auto_rules[min_completed]" min="1" max="1000" required value="{{ $rules['min_completed']??3 }}">
                      </label>
                      <label>Minimum accepted, on-time milestones
                        <input type="number" name="auto_rules[min_milestones]" min="1" max="1000" required value="{{ $rules['min_milestones']??3 }}">
                      </label>
                      <p class="badge-catalog-fixed-rule">No late or unaccepted milestone is permitted. This condition cannot be bypassed.</p>
                    @endif
                  </div>
                </details>
              @else
                <p class="badge-catalog-rule-note"><x-icon name="shield-check" size="14"/> This badge requires documented manual review; no automatic eligibility rule is configured.</p>
              @endif
              <details class="badge-catalog-extra-options">
                <summary><x-icon name="palette" size="15"/> Icon, color and order</summary>
                <div class="badge-catalog-condition-grid">
                  <label>Icon
                    <select name="icon">
                      @foreach(\App\Http\Controllers\Dashboard\CreatorBadgeController::ICONS as $icon)
                        <option value="{{ $icon }}" @selected($badge->icon===$icon)>{{ ucwords(str_replace('-',' ',$icon)) }}</option>
                      @endforeach
                    </select>
                  </label>
                  <label>Color
                    <select name="tone">
                      @foreach(\App\Http\Controllers\Dashboard\CreatorBadgeController::TONES as $tone)
                        <option value="{{ $tone }}" @selected($badge->tone===$tone)>{{ ucfirst($tone) }}</option>
                      @endforeach
                    </select>
                  </label>
                  <label>Sort order
                    <input type="number" name="sort_order" min="1" max="999" required value="{{ $badge->sort_order }}">
                  </label>
                </div>
                @if($builtIn)
                  <p>The original SVG medal artwork is preserved; icon and color affect the custom fallback only.</p>
                @endif
              </details>
              <button type="submit" class="btn primary compact badge-catalog-save">Save badge</button>
            </form>
            @unless($builtIn)
              <form method="post" action="{{ route('dashboard.badges.destroy',$badge) }}" class="badge-catalog-delete-form">
                @csrf @method('DELETE')
                <label>Type DELETE to remove this unused custom badge
                  <input name="confirmation" pattern="DELETE" required placeholder="DELETE" autocomplete="off">
                </label>
                <button type="submit" class="btn subtle compact" @disabled($badge->grants_count>0)>Delete custom badge</button>
                @if($badge->grants_count>0)<small>Revoke all existing grants before deletion.</small>@endif
              </form>
            @endunless
          @if($builtIn)
            <div class="badge-catalog-protected"><x-icon name="shield-check" size="16"/> Original Bambodia badge. You can disable it, but it cannot be deleted.</div>
          @endif
        </section>
        <details class="badge-catalog-duplicate-section">
          <summary><x-icon name="copy" size="16"/> Duplicate as custom badge</summary>
          <p>Creates a separate manual badge without copying grants. Choose a unique slug; automatic verification is never copied.</p>
          <form method="post" action="{{ route('dashboard.badges.store') }}" class="badge-catalog-duplicate-form">
            @csrf
            <input type="hidden" name="name" value="{{ \Illuminate\Support\Str::limit('Copy of '.$badge->name,90,'') }}">
            <input type="hidden" name="description" value="{{ $badge->description }}">
            <input type="hidden" name="eligibility_criteria" value="{{ $badge->eligibility_criteria??$badge->description }}">
            <input type="hidden" name="icon" value="{{ $badge->icon }}">
            <input type="hidden" name="tone" value="{{ $badge->tone }}">
            <input type="hidden" name="sort_order" value="{{ min(999,$badge->sort_order+1) }}">
            <label>New badge slug
              <input name="slug" required maxlength="70" pattern="[a-z][a-z0-9-]*" placeholder="{{ $badge->slug }}-custom" autocomplete="off">
            </label>
            <button type="submit" class="btn subtle compact"><x-icon name="copy" size="15"/> Duplicate badge</button>
          </form>
        </details>
      </dialog>
    @endforeach
  @endif

  {{-- Section 2: Only the Grant badges to creators section uses the new user table. --}}
  <section class="panel badge-manager-panel">
    <div class="panel-head badge-manager-panel-head">
      <div><h2>Grant badges to creators <span class="count">{{ $creators->total() }} users</span></h2>
        <small>10 accounts per page · Search and open Manage badges to grant or revoke</small>
      </div>
      <form method="get" action="{{ route('dashboard.badges.index') }}" class="badge-manager-search">
        <input name="search" type="search" maxlength="120" value="{{ $search }}"
               placeholder="Search name, email, username, ID" aria-label="Search users">
        <select name="role" aria-label="Filter account role">
          <option value="">All roles</option>
          @foreach(\App\Models\User::ROLES as $option)
            <option value="{{ $option }}" @selected($role===$option)>{{ ucwords(str_replace('_',' ',$option)) }}</option>
          @endforeach
        </select>
        <select name="status" aria-label="Filter account status">
          <option value="">All statuses</option>
          @foreach(['active','suspended','archived'] as $option)
            <option value="{{ $option }}" @selected($status===$option)>{{ ucfirst($option) }}</option>
          @endforeach
        </select>
        <button class="btn subtle compact" type="submit"><x-icon name="search" size="16"/> Search</button>
        <a class="btn subtle compact" href="{{ route('dashboard.badges.index') }}">Reset</a>
      </form>
    </div>
    <div class="table-scroll">
      <table class="data-table badge-manager-table">
        <thead>
          <tr><th>Public ID</th><th>User</th><th>Role</th><th>Account</th><th>Awards</th><th>Badge preview</th><th>Action</th></tr>
        </thead>
        <tbody>
        @forelse($creators as $creator)
          @php $earned=$awards[$creator->id]??collect(); @endphp
          <tr>
            <td class="mono">{{ $creator->public_id }}</td>
            <td class="badge-manager-user">
              <strong>{{ $creator->name }}</strong>
              <small>{{ $creator->email }}</small>
            </td>
            <td><span class="role-pill">{{ ucwords(str_replace('_',' ',$creator->role)) }}</span></td>
            <td><span class="user-manager-status user-manager-status--{{ $creator->account_status }}">{{ ucfirst($creator->account_status) }}</span></td>
            <td><span class="badge-manager-count">{{ $earned->count() }} / {{ $badges->where('enabled',true)->count() }}</span></td>
            <td class="badge-manager-art-cell">
              @if($earned->isNotEmpty())
                <div class="badge-manager-medal-stack" aria-label="{{ $earned->count() }} awarded badges">
                  @foreach($earned->take(4) as $badge)
                    <span title="{{ $badge->name }} — {{ $badge->description }}" class="badge-manager-mini-medal">
                      @if(is_file(public_path('images/badges/'.$badge->slug.'.svg')))
                        <img src="{{ asset('images/badges/'.$badge->slug.'.svg') }}" alt="{{ $badge->name }}" width="32" height="32" loading="lazy">
                      @else
                        <x-icon :name="$badge->icon" size="21"/>
                      @endif
                    </span>
                  @endforeach
                  @if($earned->count()>4)<small class="badge-manager-more">+{{ $earned->count()-4 }}</small>@endif
                </div>
              @else
                <span class="hint">No badges yet</span>
              @endif
            </td>
            <td><button type="button" class="btn subtle compact" data-badge-dialog-open="badge-user-{{ $creator->id }}" aria-haspopup="dialog">
              <x-icon name="award" size="15"/> Manage badges
            </button></td>
          </tr>
        @empty
          <tr><td colspan="7" class="empty-cell">No users match these filters.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
    <x-pagination :paginator="$creators" :always="true"/>
    @if($creators->lastPage()>1)
      <nav class="badge-manager-numeric-pages" aria-label="Badge Manager page numbers">
      @foreach($creators->getUrlRange(max(1,$creators->currentPage()-2),min($creators->lastPage(),$creators->currentPage()+2)) as $number=>$url)
        <a href="{{ $url }}" @if($number===$creators->currentPage()) aria-current="page" @endif>{{ $number }}</a>
      @endforeach
      </nav>
    @endif
  </section>

  @if($canEdit)
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
  @endif

  @foreach($creators as $creator)
  @php
    $earned=$awards[$creator->id]??collect();
    $manual=$grants->get($creator->id,collect());
    $owner=\App\Support\CreatorBadgeAwards::isDesignatedOwner($creator);
  @endphp
  <dialog class="config-dialog badge-manager-dialog" id="badge-user-{{ $creator->id }}" aria-labelledby="badge-dialog-title-{{ $creator->id }}">
    <div class="badge-manager-dialog-top">
      <div>
        <div class="eyebrow">Badge Management</div>
        <h2 id="badge-dialog-title-{{ $creator->id }}">{{ $creator->name }}</h2>
        <p>Public ID {{ $creator->public_id }} · {{ ucwords(str_replace('_',' ',$creator->role)) }}</p>
      </div>
      <button type="button" class="icon-button" data-badge-dialog-close aria-label="Close badge manager"><x-icon name="x" size="19"/></button>
    </div>
    <div class="badge-manager-dialog-summary">
      <strong>{{ $earned->count() }} earned badges</strong>
      <span>{{ $owner?'Owner policy awards':($creator->account_status==='active'?'Active account':'Inactive account — granting disabled') }}</span>
    </div>
    <div class="badge-manager-award-grid">
      @foreach($badges as $badge)
        @php
          $hasManual=$manual->firstWhere('creator_badge_id',$badge->id);
          $hasAward=$earned->contains('id',$badge->id);
          $isAuto=$badge->grant_mode==='auto' && $hasAward && !$hasManual && !$owner;
        @endphp
        <div class="badge-manager-award-option">
          <div class="badge-manager-award-icon">
            @if(is_file(public_path('images/badges/'.$badge->slug.'.svg')))
              <img src="{{ asset('images/badges/'.$badge->slug.'.svg') }}" alt="" width="45" height="45" loading="lazy">
            @else
              <x-icon :name="$badge->icon" size="25"/>
            @endif
          </div>
          <div class="badge-manager-award-copy">
            <strong>{{ $badge->name }}</strong>
            <small>{{ !$badge->enabled?'Disabled':($owner?'Owner':($hasManual?'Manual award':($isAuto?'Automatic award':($hasAward?'Awarded':'Not granted')))) }}</small>
          </div>
          @if($canGrant && $hasManual && !$owner)
            <form method="post" action="{{ route('dashboard.badges.revoke',[$creator,$badge]) }}">
              @csrf @method('DELETE')
              <button class="btn subtle compact" type="submit" aria-label="Revoke {{ $badge->name }} from {{ $creator->name }}">Revoke</button>
            </form>
          @elseif($hasAward)
            <x-icon name="check-circle" size="19"/>
          @endif
        </div>
      @endforeach
    </div>

    @if($recentActions->has($creator->id))
      <details class="badge-manager-history">
        <summary><x-icon name="history" size="16"/> Recent badge activity</summary>
        <div class="badge-manager-history-list">
          @foreach($recentActions->get($creator->id)->take(5) as $entry)
            <div>
              <strong>{{ ucwords(str_replace('_',' ',$entry->action)) }}</strong>
              <span>{{ $entry->reviewer_name??'Former administrator' }} · {{ $entry->created_at }}</span>
              <small>{{ $entry->reason }}</small>
            </div>
          @endforeach
        </div>
      </details>
    @endif

    @if($canGrant && $creator->account_status==='active' && !$owner)
      <form method="post" action="{{ route('dashboard.badges.grant',$creator) }}" class="badge-manager-grant-box">
        @csrf
        <h3>Grant or update a badge</h3>
        <div class="badge-manager-input-grid">
          <label>Badge
            <select name="badge_id" required>
              @foreach($badges->where('enabled',true) as $badge)
                <option value="{{ $badge->id }}">{{ $badge->name }}</option>
              @endforeach
            </select>
          </label>
          <label>Evidence or internal reason
            <input type="text" name="evidence_note" minlength="8" maxlength="1000"
                   required placeholder="Assessment, verification or support case reference">
          </label>
        </div>
        <button class="btn primary compact" type="submit">Save badge award</button>
        <p class="hint">Saving an already granted badge updates its manual evidence. Automatic metrics remain independent.</p>
      </form>
      @if(!$owner)
      <details class="badge-manager-bulk-user">
        <summary><x-icon name="layers" size="16"/> Grant all 16 badges or revoke manual awards</summary>
        <form method="post" action="{{ route('dashboard.badges.grant-all',$creator) }}">
          @csrf
          <label>Evidence covering every badge
            <textarea name="evidence_note" rows="2" minlength="20" maxlength="1000" required placeholder="Document why every selected credential and performance award is justified"></textarea>
          </label>
          <label class="badge-manager-check"><input type="checkbox" name="attest_evidence" value="1" required> I confirm that supporting evidence exists for each credential and trust-related award.</label>
          <label>Type GRANT ALL<input name="confirmation" required placeholder="GRANT ALL" pattern="GRANT ALL"></label>
          <button class="btn primary compact" type="submit">Grant all enabled badges</button>
        </form>
        <form method="post" action="{{ route('dashboard.badges.revoke-all',$creator) }}" class="badge-manager-revoke-all">
          @csrf @method('DELETE')
          <label>Reason to revoke all manual grants
            <input name="reason" required minlength="12" maxlength="1000" placeholder="Internal reason for revocation">
          </label>
          <label>Type REVOKE ALL<input name="confirmation" required placeholder="REVOKE ALL" pattern="REVOKE ALL"></label>
          <button class="btn subtle compact" type="submit">Revoke all manual grants</button>
        </form>
      </details>
      @endif
    @else
      <p class="hint">{{ $owner?'The designated Superadmin receives all enabled medals by policy.':(!$canGrant?'Your role does not have badge granting permission.':'This account is inactive, so new grants are unavailable.') }}</p>
    @endif
  </dialog>
  @endforeach

  @if($canGrant && auth()->user()->role==='super_admin')
  <dialog id="badge-global-grant" class="config-dialog badge-manager-dialog badge-manager-global-dialog" aria-labelledby="badge-global-title">
    <div class="badge-manager-dialog-top">
      <div><div class="eyebrow">Superadmin action</div><h2 id="badge-global-title">Grant all badges to all users</h2></div>
      <button type="button" class="icon-button" data-badge-dialog-close aria-label="Close global grants"><x-icon name="x" size="19"/></button>
    </div>
    <p>This grants every enabled badge to <strong>all active accounts</strong>, including customers and staff. Claims such as Identity Verified and Expert Vetted require genuine supporting evidence for every recipient. This action is audited and does not overwrite existing evidence.</p>
    <form method="post" action="{{ route('dashboard.badges.grant-everyone') }}" class="badge-manager-global-form">
      @csrf
      <label>Documented evidence for all recipients
        <textarea name="evidence_note" required minlength="20" maxlength="1000" rows="4" placeholder="Reference to a completed and documented review covering all active users"></textarea>
      </label>
      <label class="badge-manager-check">
        <input type="checkbox" name="attest_evidence" value="1" required>
        I confirm appropriate, independent evidence for all badges and all recipients.
      </label>
      <label>Type GRANT EVERY USER to continue
        <input name="confirmation" type="text" required pattern="GRANT EVERY USER" autocomplete="off" placeholder="GRANT EVERY USER">
      </label>
      <div class="dialog-actions"><button type="button" class="btn subtle" data-badge-dialog-close>Cancel</button>
        <button type="submit" class="btn primary">Grant to all active users</button>
      </div>
    </form>
  </dialog>
  @endif
</div>
@endsection
