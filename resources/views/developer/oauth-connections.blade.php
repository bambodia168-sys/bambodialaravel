@extends('layouts.workspace')
@section('workspace','developer')
@section('title','OAuth Connections')

@push('workspace-head')
  <link rel="stylesheet" href="{{ asset('css/mcp-oauth-connections.css') }}?v=1">
@endpush

@section('content')
<div class="mcp-oauth-page">
  <header class="mcp-oauth-heading">
    <div class="mcp-oauth-heading__copy">
      <div class="eyebrow">Integrations &amp; Credentials</div>
      <h1>OAuth Connections</h1>
      <p>Review applications connected to your Bambodia administrator account, manage their permissions, and revoke access.</p>
    </div>
    <div class="mcp-oauth-heading__actions">
      <a class="btn subtle compact" href="{{ route('developer.index') }}"><x-icon name="arrow-left" size="16"/> Developer dashboard</a>
      <a class="btn primary compact" href="{{ route('developer.mcp.index') }}"><x-icon name="plug" size="16"/> Connect ChatGPT</a>
    </div>
  </header>

  @if($errors->any())
    <div class="mcp-oauth-alert" role="alert"><x-icon name="alert" size="17"/> Could not complete the request. Confirm your administrator password and try again.</div>
  @endif

  <div class="mcp-oauth-summary">
    <article class="mcp-oauth-metric">
      <div class="mcp-oauth-metric__icon"><x-icon name="shield-check" size="18"/></div>
      <div><span>Connection records</span><strong>{{ $tokens->total() }}</strong><small>Access limited to your account</small></div>
    </article>
    <article class="mcp-oauth-metric">
      <div class="mcp-oauth-metric__icon"><x-icon name="lock" size="18"/></div>
      <div><span>Access management</span><strong>Protected</strong><small>Revoke requires your password</small></div>
    </article>
  </div>

  <section class="panel mcp-oauth-panel" aria-labelledby="mcp-oauth-list-title">
    <div class="panel-head mcp-oauth-panel__head">
      <div>
        <h2 id="mcp-oauth-list-title">Authorized application connections <span class="count">{{ $tokens->total() }}</span></h2>
        <p>OAuth tokens with MCP access permission, including expired or revoked connections.</p>
      </div>
      @if($tokens->total() > 0)
        <a class="btn subtle compact" href="{{ route('developer.mcp.index') }}"><x-icon name="plug" size="15"/> Connection Center</a>
      @endif
    </div>

    @if($tokens->isEmpty())
      <div class="mcp-oauth-empty">
        <div class="mcp-oauth-empty__icon"><x-icon name="link" size="25"/></div>
        <h3>No OAuth connections yet</h3>
        <p>ChatGPT has not yet been authorized through Bambodia OAuth for this account. You can configure the MCP endpoint from the Connection Center, then approve access in the ChatGPT authorization flow.</p>
        <a class="btn primary compact" href="{{ route('developer.mcp.index') }}">
          <x-icon name="plug" size="16"/> Go to Connection Center
        </a>
      </div>
    @else
      <div class="table-scroll">
        <table class="data-table mcp-oauth-table">
          <thead>
            <tr>
              <th scope="col">Application</th>
              <th scope="col">Permissions</th>
              <th scope="col">Issued</th>
              <th scope="col">Expires</th>
              <th scope="col">Status</th>
              <th scope="col" class="mcp-oauth-table__action">Action</th>
            </tr>
          </thead>
          <tbody>
            @foreach($tokens as $token)
              <tr>
                <td><strong>{{ $token->client?->name ?: 'OAuth client' }}</strong><small>OAuth application</small></td>
                <td>
                  <div class="mcp-oauth-scopes">
                    @forelse(($token->scopes ?? []) as $scope)
                      <span class="mcp-oauth-scope">{{ $scope }}</span>
                    @empty
                      <span class="hint">None</span>
                    @endforelse
                  </div>
                </td>
                <td class="mcp-oauth-date">{{ $token->created_at?->format('d M Y H:i') ?? '—' }}</td>
                <td class="mcp-oauth-date">{{ $token->expires_at?->format('d M Y H:i') ?? '—' }}</td>
                <td>
                  @if($token->revoked)
                    <span class="mcp-oauth-state mcp-oauth-state--inactive"><x-icon name="x-circle" size="13"/> Revoked</span>
                  @elseif($token->expires_at?->isPast())
                    <span class="mcp-oauth-state mcp-oauth-state--inactive"><x-icon name="clock" size="13"/> Expired</span>
                  @else
                    <span class="mcp-oauth-state mcp-oauth-state--active"><x-icon name="check-circle" size="13"/> Active</span>
                  @endif
                </td>
                <td class="mcp-oauth-table__action">
                  @unless($token->revoked)
                    <button type="button" class="btn subtle compact" data-modal-open="mcp-oauth-revoke-{{ $token->getKey() }}">
                      <x-icon name="shield" size="15"/> Revoke
                    </button>
                  @else
                    <span class="hint">—</span>
                  @endunless
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </div>

      @if($tokens->lastPage() > 1)
        <div class="mcp-oauth-pagination">
          <div class="mcp-oauth-pagination__links">{{ $tokens->links() }}</div>
          <form class="go-form" method="GET" action="{{ route('developer.oauth.index') }}">
            <label for="mcp-oauth-go-page">Go to page</label>
            <input id="mcp-oauth-go-page" type="number" name="page" min="1"
                max="{{ $tokens->lastPage() }}" value="{{ $tokens->currentPage() }}" required>
            <button class="btn subtle compact" type="submit">Go</button>
          </form>
        </div>
      @endif
    @endif
  </section>

  @if($tokens->isNotEmpty())
    @foreach($tokens as $token)
      @unless($token->revoked)
        <dialog class="config-dialog mcp-oauth-dialog" id="mcp-oauth-revoke-{{ $token->getKey() }}" aria-labelledby="mcp-oauth-revoke-title-{{ $token->getKey() }}">
          <h2 id="mcp-oauth-revoke-title-{{ $token->getKey() }}">Revoke OAuth connection</h2>
          <p>This will stop access for <strong>{{ $token->client?->name ?: 'this OAuth application' }}</strong> and revoke its associated refresh token. Reconnection will require a new authorization.</p>
          <form action="{{ route('developer.oauth.revoke', $token->getKey()) }}" method="POST">
            @csrf
            @method('DELETE')
            <label for="mcp-oauth-password-{{ $token->getKey() }}">Confirm your administrator password</label>
            <input id="mcp-oauth-password-{{ $token->getKey() }}" name="password" type="password" autocomplete="current-password" required>
            <div class="dialog-actions">
              <button type="button" class="btn subtle compact" data-modal-close>Cancel</button>
              <button type="submit" class="btn primary compact"><x-icon name="shield" size="15"/> Revoke access</button>
            </div>
          </form>
        </dialog>
      @endunless
    @endforeach
  @endif
</div>
@endsection
