@extends('layouts.workspace')
@section('workspace','developer')
@section('title','Bambodia Manager MCP')
@push('workspace-head')
  <link rel="stylesheet" href="{{ asset('css/mcp-connection-center.css') }}?v=1">
@endpush
@section('content')
<div class="mcp-center">
  <header class="mcp-heading">
    <div class="mcp-heading__intro">
      <div class="eyebrow">Integrations &amp; Credentials</div>
      <h1>Bambodia Manager MCP</h1>
      <p>Securely connect ChatGPT to the main Bambodia website.</p>
    </div>
    <div class="mcp-heading__actions">
      <a class="btn subtle compact" href="{{ route('developer.index') }}">Developer dashboard</a>
      <a class="btn subtle compact" href="{{ route('developer.oauth.index') }}">OAuth connections</a>
    </div>
  </header>
  <div class="mcp-status-grid">
    <div class="mcp-status"><div class="mcp-status__content">
      <span class="mcp-status__label">Production MCP</span>
      <strong>{{ $enabled ? 'Enabled' : 'Not yet enabled' }}</strong>
      <span>{{ $enabled ? 'Administrator authorization required' : 'Awaiting production release approval' }}</span>
    </div></div>
    <div class="mcp-status"><div class="mcp-status__content">
      <span class="mcp-status__label">OAuth storage</span>
      <strong>{{ $oauthStorageReady ? 'Available' : 'Not installed' }}</strong>
      <span>Uses the main website account database</span>
    </div></div>
    <div class="mcp-status"><div class="mcp-status__content">
      <span class="mcp-status__label">Active connections</span>
      <strong>{{ $activeConnections }}</strong>
      <a href="{{ route('developer.oauth.index') }}">Review and revoke connections</a>
    </div></div>
  </div>

  <section class="panel mcp-panel">
    <div class="mcp-panel__head">
      <div class="mcp-step">01</div><div class="mcp-panel__heading">
        <h2>Connect with ChatGPT</h2>
        <p>The main Bambodia domain will host the permanent MCP and OAuth endpoints.</p>
      </div>
      <span class="mcp-tag">OAuth 2.1</span>
    </div>
    <div class="mcp-panel__body">
      <label for="mcp-main-endpoint" class="mcp-field__label">MCP server endpoint</label>
      <div class="mcp-endpoint">
        <code id="mcp-main-endpoint">{{ $mcpUrl }}</code>
        <button class="btn primary compact" type="button" data-copy-target="mcp-main-endpoint">Copy endpoint</button>
      </div>
      <div class="mcp-panel__footer">
        <p class="mcp-helper">ChatGPT authorization requires administrator sign-in and approval. Do not enter a user password as an OAuth client secret.</p>
        <div class="mcp-links">
          <a class="btn subtle compact" href="{{ route('developer.oauth.index') }}">Manage connections</a>
          <a class="btn subtle compact" href="{{ url('/.well-known/oauth-authorization-server') }}" target="_blank" rel="noopener noreferrer">OAuth discovery</a>
        </div>
      </div>
    </div>
  </section>
  <section class="panel mcp-panel">
    <div class="mcp-panel__head">
      <div class="mcp-step">02</div><div class="mcp-panel__heading">
        <h2>Permissions and release status</h2>
        <p>Production data is protected until the independent OAuth release gates pass.</p>
      </div>
    </div>
    <div class="mcp-panel__body">
      <ol class="mcp-checklist">
        <li><span class="mcp-checklist__marker">1</span><div><strong>Read authorization</strong><span>The mcp:use scope requires active super-admin consent.</span></div></li>
        <li><span class="mcp-checklist__marker">2</span><div><strong>Write authorization</strong><span>{{ $writesEnabled ? 'Explicitly enabled after review' : 'Disabled pending separate production approval' }}</span></div></li>
        <li><span class="mcp-checklist__marker">3</span><div><strong>Connection revocation</strong><span>Revoke access and refresh credentials in OAuth Connections.</span></div></li>
        <li><span class="mcp-checklist__marker">4</span><div><strong>Isolated QA</strong><span>The previous QA database, administrator credentials and drafts stay separate.</span></div></li>
      </ol>
    </div>
  </section>
</div>
@endsection
