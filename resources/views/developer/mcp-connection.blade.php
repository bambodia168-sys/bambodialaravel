@extends('layouts.workspace')
@section('workspace','developer')
@section('title','MCP Connection Center')
@section('content')
<div class="page-intro">
    <div class="eyebrow">Integrations &amp; Credentials</div>
    <h1>Bambodia MCP Connection Center</h1>
    <p>Connect ChatGPT to Bambodia, check the real MCP service, and manage authorized connections. This workspace never displays passwords, bearer tokens or signing keys.</p>
</div>
<div class="workspace-quick-actions">
    <a class="btn subtle compact" href="{{ route('developer.index') }}"><x-icon name="arrow-left" size="16"/> Developer dashboard</a>
    <a class="btn subtle compact" href="{{ route('developer.oauth.index') }}"><x-icon name="shield" size="16"/> OAuth connections</a>
</div>

@if(session('mcp_check_success'))
    <div class="workspace-overview-alert" role="status"><x-icon name="check-circle" size="16"/> {{ session('mcp_check_success') }}</div>
@endif
@if(session('mcp_check_error'))
    <div class="workspace-overview-alert" role="alert"><x-icon name="alert" size="16"/> {{ session('mcp_check_error') }}</div>
@endif
@if($errors->any())
    <div class="workspace-overview-alert" role="alert">Connection test not started. Confirm your account password and try again.</div>
@endif

<div class="workspace-overview-grid" aria-label="Connection readiness summary">
    <div class="panel" style="padding:18px">
        <div class="eyebrow">Environment</div>
        <h2 style="margin:8px 0">{{ $qa ? 'Isolated QA' : 'Current installation' }}</h2>
        <p class="hint">{{ $qa ? 'Real HTTPS smoke checks are enabled for the QA hostname.' : 'Automated connection checks are disabled outside the isolated QA hostname.' }}</p>
    </div>
    <div class="panel" style="padding:18px">
        <div class="eyebrow">OAuth database</div>
        <h2 style="margin:8px 0">{{ $oauthStorageReady ? 'Available' : 'Not installed' }}</h2>
        <p class="hint">This checks Passport tables, not whether external OAuth authorization works.</p>
    </div>
    <div class="panel" style="padding:18px">
        <div class="eyebrow">Your active OAuth tokens</div>
        <h2 style="margin:8px 0">{{ $activeConnections }}</h2>
        <p class="hint">Access tokens with MCP use permission. View and revoke them in OAuth Connections.</p>
    </div>
</div>

<section class="panel workspace-overview-block">
    <div class="panel-head">
        <div>
            <h2>1. Connect with ChatGPT</h2>
            <p>Use the following endpoint in ChatGPT's custom MCP connection settings when this QA site is publicly accessible and verified.</p>
        </div>
    </div>
    <div class="token-secret" style="margin:12px 0">
        <code id="bambodia-mcp-endpoint" style="overflow-wrap:anywhere">{{ $mcpUrl }}</code>
        <button type="button" class="btn primary" data-copy-target="bambodia-mcp-endpoint">Copy endpoint</button>
    </div>
    <p class="hint">Choose OAuth authorization in ChatGPT. Sign in with your Bambodia administrator account, inspect permissions and approve access. Test read-only tools first. The exact ChatGPT callback URL comes from ChatGPT; never guess it.</p>
    <div class="workspace-quick-actions">
        <a class="btn subtle compact" href="{{ route('developer.oauth.index') }}"><x-icon name="shield" size="16"/> Review connected clients</a>
        <a class="btn subtle compact" href="{{ url('/.well-known/oauth-authorization-server') }}" target="_blank" rel="noopener noreferrer"><x-icon name="external-link" size="16"/> OAuth discovery</a>
    </div>
</section>

<section class="panel workspace-overview-block">
    <div class="panel-head">
        <div>
            <h2>2. Test the live MCP service</h2>
            <p>One click tests real HTTPS calls to the MCP endpoint: tool discovery, overview and post listing. It uses a short-lived read-only credential that is revoked immediately afterward.</p>
        </div>
    </div>
    @if($qa)
    <form method="POST" action="{{ route('developer.mcp.check') }}" style="max-width:500px">
        @csrf
        <label for="mcp-check-password">Confirm your administrator password</label>
        <input id="mcp-check-password" name="password" type="password" autocomplete="current-password" required>
        <div class="dialog-actions">
            <button type="submit" class="btn primary"><x-icon name="play" size="16"/> Run live read-only check</button>
        </div>
    </form>
    @else
        <p class="hint">This action is deliberately limited to <code>https://oauth-qa.bambodia.com</code> with APP_ENV=staging, so production credentials and records cannot be used for this probe.</p>
    @endif
    <p class="hint">A pass proves the deployed HTTPS transport and read permission are working. It does not prove OAuth PKCE/consent, token refresh, or write tools. Test those next through an actual ChatGPT connection.</p>
</section>

<section class="panel workspace-overview-block">
    <div class="panel-head">
        <div>
            <h2>3. Verify real user actions</h2>
            <p>After ChatGPT completes OAuth authorization, run these requests in the connected chat and compare the result with your Bambodia dashboard.</p>
        </div>
    </div>
    <ol style="line-height:2;padding-left:24px">
        <li><strong>Discover:</strong> "Show the tools available in Bambodia Manager."</li>
        <li><strong>Read:</strong> "List the latest Bambodia blog posts."</li>
        <li><strong>Create:</strong> "Create an unpublished test blog-post draft titled QA MCP Connection Example." Review and approve the write permission first.</li>
        <li><strong>Verify:</strong> Open Post Manager in Bambodia and confirm the new draft appears with the same title.</li>
        <li><strong>Edit and revoke:</strong> Update that disposable draft, then revoke the OAuth connection here and confirm that further protected calls are rejected.</li>
    </ol>
    <div class="workspace-quick-actions">
        <a class="btn subtle compact" href="{{ route('developer.oauth.index') }}"><x-icon name="shield" size="16"/> Revoke OAuth access</a>
        <a class="btn subtle compact" href="{{ route('developer.docs') }}"><x-icon name="book" size="16"/> API and tool documentation</a>
    </div>
    <p class="hint">Production publication, legal approvals, uploads, deployments and security configuration are intentionally outside this automated test workflow.</p>
</section>
@endsection
