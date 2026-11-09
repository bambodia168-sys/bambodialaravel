@extends('layouts.dashboard')
@section('title','Creator Badges')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Administration · Trust and reputation</div><h1>Creator Badges</h1><p>Configure each recognition badge, verify eligibility and manage manual awards. Public profiles show only active badges.</p></div>
  <section class="panel">
    <div class="panel-head"><h2>Badge catalog <span class="count">{{ $badges->count() }}</span></h2></div>
    <div class="creator-badge-admin-grid">
      @foreach($badges as $badge)
        <form class="creator-badge-admin-card" method="post" action="{{ route('dashboard.badges.update',$badge) }}">
          @csrf @method('PATCH')
          <div class="creator-badge-admin-preview"><x-creator-badges :badges="collect([$badge])"/></div>
          <label>Badge name<input name="name" value="{{ $badge->name }}" maxlength="90" required></label>
          <label>Hover description<textarea name="description" rows="3" maxlength="1000" required>{{ $badge->description }}</textarea></label>
          <div class="creator-badge-admin-options">
            <label>Grant mode<select name="grant_mode">
              <option value="manual" @selected($badge->grant_mode==='manual')>Manual — Admin / Staff</option>
              <option value="auto" @selected($badge->grant_mode==='auto') @disabled(!in_array($badge->slug,['rising-talent','repeat-clients','on-time-delivery'],true))>Auto — verified metrics</option>
            </select></label>
            <label>Status<select name="enabled"><option value="1" @selected($badge->enabled)>Enabled</option><option value="0" @selected(!$badge->enabled)>Disabled</option></select></label>
          </div>
          <button class="btn primary compact" type="submit">Save badge</button>
        </form>
      @endforeach
    </div>
  </section>
  <section class="panel">
    <div class="panel-head"><h2>Grant badges to creators</h2><form method="get" class="table-search"><input type="search" name="search" value="{{ $q }}" placeholder="Search creators" aria-label="Search creators"><button class="btn subtle compact">Search</button></form></div>
    <div class="creator-badge-admin-people">
    @foreach($creators as $creator)
      <article class="creator-badge-person">
        <div><strong>{{ $creator->name }}</strong> <small>· {{ $creator->public_id }}</small></div>
        <x-creator-badges :badges="\App\Support\CreatorBadgeAwards::earned($creator,$badges)"/>
        <form method="post" action="{{ route('dashboard.badges.grant',$creator) }}" class="creator-badge-grant-form">
          @csrf
          <label>Manual badge<select name="badge_id" required>
            @foreach($badges->where('enabled',true)->where('grant_mode','manual') as $badge)
              <option value="{{ $badge->id }}">{{ $badge->name }}</option>
            @endforeach
          </select></label>
          <label>Verification evidence / reason<input name="evidence_note" minlength="8" maxlength="1000" required placeholder="Internal review reference or evidence"></label>
          <button type="submit" class="btn primary compact">Grant</button>
        </form>
        @foreach($grants->get($creator->id,collect()) as $grant)
          @php $grantedBadge=$badges->firstWhere('id',$grant->creator_badge_id); @endphp
          @if($grantedBadge)
            <form method="post" action="{{ route('dashboard.badges.revoke',[$creator,$grantedBadge]) }}" class="creator-badge-revoke">
              @csrf @method('DELETE') <span>{{ $grantedBadge->name }}</span><button class="btn subtle compact" type="submit">Revoke</button>
            </form>
          @endif
        @endforeach
      </article>
    @endforeach
    </div>
    <x-pagination :paginator="$creators"/>
  </section>
</div>
@endsection
