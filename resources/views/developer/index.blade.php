@extends('layouts.dashboard')
@section('title','Developer Dashboard')
@section('content')
<div class="page-intro">
  <div class="eyebrow">Integrations & Credentials</div>
  <h1>Developer Dashboard</h1>
  <p>Issue scoped access credentials for Bambodia's implemented read-only endpoints. Tokens are shown only once and stored as hashes.</p>
</div>
@if(session('issued_token'))
<section class="panel issued-token">
  <div class="panel-head"><h2>New access token — copy now</h2></div>
  <div class="token-secret"><code id="new-token-secret">{{ session('issued_token') }}</code><button type="button" class="btn primary" data-copy-target="new-token-secret">Copy</button></div>
  <p class="hint">This token will not be shown again after you leave or refresh this page. Store it in your server-side secrets manager, never in public JavaScript.</p>
</section>
@endif
<section class="panel">
  <div class="panel-head"><div><h2>API access tokens <span class="count">{{ $tokens->total() }}</span></h2><p>Tokens automatically expire and can be revoked immediately.</p></div>
    <div class="toolbar-actions"><a class="btn subtle" href="{{ route('developer.docs') }}"><x-icon name="book" size="16"/> API documentation</a><button type="button" class="btn primary" data-modal-open="new-api-token"><x-icon name="key" size="16"/> Create token</button></div>
  </div>
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
        <td><button class="btn subtle compact" type="button" data-modal-open="token-{{ $token->id }}"><x-icon name="settings" size="15"/> Configure</button>
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
      <tr><td class="empty-cell" colspan="7">No API tokens have been issued.</td></tr>
    @endforelse
    </tbody></table>
  </div>
  <x-pagination :paginator="$tokens"/>
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
