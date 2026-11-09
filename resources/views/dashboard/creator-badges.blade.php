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
           title="Badge catalog settings" aria-label="Open badge settings"><x-icon name="settings" size="20"/></a>
      @endif
      @if($canGrant && auth()->user()->role==='super_admin')
        <button type="button" class="btn subtle" data-badge-dialog-open="badge-global-grant">
          <x-icon name="users" size="17"/> Grant all to users
        </button>
      @endif
    </div>
  </header>

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
