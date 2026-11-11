@extends('layouts.workspace')
@section('workspace','developer')
@section('title','Developer Dashboard')
@section('content')
<div class="page-intro">
  <div class="eyebrow">Integrations & Credentials</div>
  <h1>Developer Dashboard</h1>
  <p>Issue scoped access credentials for Bambodia's implemented read-only endpoints. Tokens are shown only once and stored as hashes.</p>
</div>
<div class="workspace-overview-grid" aria-label="Your API credential health">
  <x-workspace-metric label="Active tokens" :value="$tokenStats['active']" icon="key-round"
    :href="route('developer.index',['status'=>'active'])" action="View active"/>
  <x-workspace-metric label="Expiring within 7 days" :value="$tokenStats['expiring']" icon="alert"
    :href="route('developer.index',['status'=>'expiring'])" action="Review expiry"/>
  <x-workspace-metric label="Expired tokens" :value="$tokenStats['expired']" icon="clock"
    :href="route('developer.index',['status'=>'expired'])" action="View expired"/>
  <x-workspace-metric label="Revoked tokens" :value="$tokenStats['revoked']" icon="shield"
    :href="route('developer.index',['status'=>'revoked'])" action="Review revoked"/>
</div>
<div class="workspace-quick-actions">
  <a class="btn subtle compact" href="{{ route('developer.docs') }}"><x-icon name="book" size="16"/> API endpoints and scope reference</a>
  <a class="btn subtle compact" href="{{ route('developer.oauth.index') }}"><x-icon name="shield" size="16"/> OAuth connections</a>
  <a class="btn subtle compact" href="{{ route('developer.index',['status'=>'active']) }}#api-token-list">
    <x-icon name="key" size="16"/> Review active credentials ({{ $tokenStats['active'] }})
  </a>
</div>
<p class="hint">Credential diagnostics: {{ $tokenStats['unused'] }} active {{ $tokenStats['unused']===1?'token has':'tokens have' }} never been used.
  Only token prefixes, scopes and usage timestamps are shown. Credentials are not tested using their secrets here.</p>
@if($tokenStats['expiring']>0)
  <p class="workspace-overview-alert"><x-icon name="alert" size="17"/>
    {{ $tokenStats['expiring'] }} active {{ $tokenStats['expiring']===1?'token expires':'tokens expire' }} within 7 days.
    Review your integration and create a new scoped token before it expires.</p>
@endif
@if(session('issued_token'))
<section class="panel issued-token">
  <div class="panel-head"><h2>New access token — copy now</h2></div>
  <div class="token-secret"><code id="new-token-secret">{{ session('issued_token') }}</code><button type="button" class="btn primary" data-copy-target="new-token-secret">Copy</button></div>
  <p class="hint">This token will not be shown again after you leave or refresh this page. Store it in your server-side secrets manager, never in public JavaScript.</p>
</section>
@endif
<section class="panel workspace-overview-block" id="api-token-list">
  <div class="panel-head"><div><h2>API access tokens <span class="count">{{ $tokens->total() }}</span></h2><p>Tokens automatically expire and can be revoked immediately.</p></div>
    <div class="toolbar-actions"><a class="btn subtle" href="{{ route('developer.docs') }}"><x-icon name="book" size="16"/> API documentation</a><button type="button" class="btn primary" data-modal-open="new-api-token"><x-icon name="key" size="16"/> Create token</button></div>
  </div>
  <nav class="workspace-token-filters" aria-label="Filter API tokens">
    @foreach(['all'=>'All tokens','active'=>'Active','expiring'=>'Expiring soon','expired'=>'Expired','revoked'=>'Revoked'] as $filter=>$label)
      <a class="{{ $status===$filter ? 'is-active' : '' }}"
         href="{{ route('developer.index',$filter==='all'?[]:['status'=>$filter]) }}#api-token-list"
         @if($status===$filter)aria-current="page"@endif>{{ $label }}</a>
    @endforeach
  </nav>
  <div class="table-scroll">
    <table class="data-table"><thead><tr><th>Token name</th><th>Identifier</th><th>Permissions</th><th>Expires</th><th>Last used</th><th>Status</th><th>Action</th></tr></thead><tbody>
    @forelse($tokens as $token)
      <tr>
        <td><strong>{{ $token->name }}</strong></td>
        <td class="mono">{{ $token->prefix }}…</td>
        <td>{{ implode(', ', $token->scopes) }}</td>
        <td>{{ $token->expires_at->format('d M Y') }}</td>
        <td>{{ $token->last_used_at?->format('d M Y H:i') ?? 'Never' }}</td>
        <td><span class="role-pill">{{ $token->revoked_at ? 'Revoked' : ($token->expires_at->isPast() ? 'Expired' : 'Active') }}</span></td>
        <td><x-row-action-menu :id="'token-'.$token->id" :label="$token->name"><button type="button" data-modal-open="token-{{ $token->id }}"><x-icon name="settings" size="16"/> Configure</button></x-row-action-menu>
          <dialog id="token-{{ $token->id }}" class="config-dialog">
            <div class="dialog-heading"><h2>{{ $token->name }}</h2><p>{{ $token->prefix }}…</p></div>
            <p><strong>Scopes:</strong> {{ implode(', ', $token->scopes) }}</p>
            <p><strong>Expires:</strong> {{ $token->expires_at->format('d M Y H:i') }}</p>
            @if(!$token->revoked_at)
              <form method="post" action="{{ route('developer.revoke', $token) }}">
                @csrf @method('DELETE')
                <label for="revoke-password-{{ $token->id }}">Confirm your password to revoke</label>
                <input id="revoke-password-{{ $token->id }}" type="password" name="password" autocomplete="current-password" required>
                <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button type="submit" class="btn primary">Revoke token</button></div>
              </form>
            @else
              <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Close</button></div>
            @endif
          </dialog>
        </td>
      </tr>
    @empty
      <tr><td class="empty-cell" colspan="7">{{ $status==='all' ? 'No API tokens have been issued.' : 'No tokens match this status.' }}</td></tr>
    @endforelse
    </tbody></table>
  </div>
  <x-pagination :paginator="$tokens"/>
</section>
<section class="panel workspace-overview-block" aria-labelledby="developer-event-title">
  <div class="panel-head"><div><h2 id="developer-event-title">Credential activity history</h2>
    <p>Recent scoped token issuance, revocation and API usage events for your administrator account.</p></div></div>
  <div class="workspace-overview-list">
    @forelse($recentActivity as $activity)
      <div class="workspace-overview-row">
        <span class="workspace-overview-row-main">
          <strong>{{ str_replace('.',' / ',$activity->event) }}</strong>
          <small>{{ $activity->created_at->format('d M Y H:i') }}</small>
        </span><span class="role-pill">Recorded</span>
      </div>
    @empty<p class="workspace-overview-empty">No recorded credential activity yet.</p>
    @endforelse
  </div>
</section>
<dialog id="new-api-token" class="config-dialog">
  <form method="post" action="{{ route('developer.store') }}">
    @csrf
    <h2>Create API access token</h2>
    <p>Credentials authorize only the API endpoints selected below.</p>
    <label for="token-name">Token name</label>
    <input id="token-name" name="name" maxlength="80" minlength="3" required placeholder="Integration or server name">
    <label for="token-days">Expiration</label>
    <select id="token-days" name="days"><option value="7">7 days</option><option value="30" selected>30 days</option><option value="90">90 days</option></select>
    <fieldset class="scope-options"><legend>Read permissions</legend>
      @foreach($scopes as $scope)
      <label><input type="checkbox" name="scopes[]" value="{{ $scope }}" @checked($loop->first)> {{ $scope }}</label>
      @endforeach
    </fieldset>
    <label for="token-password">Confirm your account password</label>
    <input id="token-password" name="password" type="password" autocomplete="current-password" required>
    <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button type="submit" class="btn primary">Create token</button></div>
  </form>
</dialog>
@endsection
