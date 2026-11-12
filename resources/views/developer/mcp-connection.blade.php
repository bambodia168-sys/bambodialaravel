@extends('layouts.workspace')
@section('workspace','developer')
@section('title','MCP Connection Center')

@push('workspace-head')
    <link rel="stylesheet" href="{{ asset('css/mcp-connection-center.css') }}?v=1">
@endpush

@section('content')
<div class="mcp-center">
    <header class="mcp-heading">
        <div class="mcp-heading__intro">
            <div class="eyebrow">Integrations &amp; Credentials</div>
            <h1>Bambodia MCP Connection Center</h1>
            <p>Connect ChatGPT, verify your live MCP tools, and manage access from one secure workspace.</p>
        </div>
        <nav class="mcp-heading__actions" aria-label="MCP workspace navigation">
            <a class="btn subtle compact" href="{{ route('developer.index') }}">
                <x-icon name="arrow-left" size="16"/> Developer dashboard
            </a>
            <a class="btn subtle compact" href="{{ route('developer.oauth.index') }}">
                <x-icon name="shield" size="16"/> OAuth connections
            </a>
        </nav>
    </header>

    @if(session('mcp_check_success'))
        <div class="mcp-notice mcp-notice--success" role="status">
            <x-icon name="check-circle" size="18"/>
            <span>{{ session('mcp_check_success') }}</span>
        </div>
    @endif

    @if(session('mcp_check_error'))
        <div class="mcp-notice mcp-notice--error" role="alert">
            <x-icon name="alert" size="18"/>
            <span>{{ session('mcp_check_error') }}</span>
        </div>
    @endif

    @if(session('mcp_draft_created'))
        <div class="mcp-notice mcp-notice--success" role="status">
            <x-icon name="check-circle" size="18"/>
            <span>The live MCP tool created the unpublished draft <strong>{{ session('mcp_draft_created.title') }}</strong>.</span>
            <a class="btn subtle compact" href="{{ route('dashboard.posts.edit', session('mcp_draft_created.id')) }}">
                Open in Post Manager <x-icon name="external-link" size="15"/>
            </a>
        </div>
    @endif

    @if($errors->any())
        <div class="mcp-notice mcp-notice--error" role="alert">
            <x-icon name="alert" size="18"/>
            <span>Action not started. Confirm your administrator password and any required approval checkbox.</span>
        </div>
    @endif

    <div class="mcp-status-grid" aria-label="MCP connection readiness">
        <div class="mcp-status">
            <div class="mcp-status__icon"><x-icon name="globe" size="18"/></div>
            <div class="mcp-status__content">
                <span class="mcp-status__label">Environment</span>
                <strong>{{ $qa ? 'Isolated QA' : 'Current installation' }}</strong>
                <span>{{ $qa ? 'QA-only actions enabled' : 'QA actions unavailable here' }}</span>
            </div>
        </div>
        <div class="mcp-status">
            <div class="mcp-status__icon"><x-icon name="database" size="18"/></div>
            <div class="mcp-status__content">
                <span class="mcp-status__label">OAuth database</span>
                <strong>{{ $oauthStorageReady ? 'Available' : 'Not installed' }}</strong>
                <span>Passport storage status</span>
            </div>
        </div>
        <div class="mcp-status">
            <div class="mcp-status__icon"><x-icon name="shield" size="18"/></div>
            <div class="mcp-status__content">
                <span class="mcp-status__label">Your active OAuth tokens</span>
                <strong>{{ $activeConnections }}</strong>
                <a href="{{ route('developer.oauth.index') }}">Review connections <x-icon name="arrow-right" size="13"/></a>
            </div>
        </div>
    </div>

    <section class="panel mcp-panel" aria-labelledby="mcp-connect-title">
        <div class="mcp-panel__head">
            <div class="mcp-step" aria-hidden="true">01</div>
            <div class="mcp-panel__heading">
                <h2 id="mcp-connect-title">Connect with ChatGPT</h2>
                <p>Use the MCP endpoint below when setting up Bambodia Manager in ChatGPT.</p>
            </div>
            <span class="mcp-tag">OAuth 2.1</span>
        </div>
        <div class="mcp-panel__body">
            <label class="mcp-field__label" for="bambodia-mcp-endpoint">MCP server endpoint</label>
            <div class="mcp-endpoint">
                <code id="bambodia-mcp-endpoint">{{ $mcpUrl }}</code>
                <button class="btn primary compact" type="button" data-copy-target="bambodia-mcp-endpoint">
                    <x-icon name="copy" size="16"/> Copy endpoint
                </button>
            </div>
            <div class="mcp-panel__footer">
                <p class="mcp-helper"><x-icon name="info" size="15"/> ChatGPT must complete OAuth authorization and consent separately. Use the callback URL supplied by ChatGPT; do not guess it.</p>
                <div class="mcp-links">
                    <a href="{{ route('developer.oauth.index') }}" class="btn subtle compact"><x-icon name="shield" size="15"/> Connections</a>
                    <a href="{{ url('/.well-known/oauth-authorization-server') }}" class="btn subtle compact" target="_blank" rel="noopener noreferrer"><x-icon name="external-link" size="15"/> OAuth discovery</a>
                </div>
            </div>
        </div>
    </section>

    <div class="mcp-action-grid" aria-label="Run real MCP operations">
        <section class="panel mcp-panel mcp-panel--action" aria-labelledby="mcp-read-title">
            <div class="mcp-panel__head">
                <div class="mcp-step" aria-hidden="true">02</div>
                <div class="mcp-panel__heading">
                    <h2 id="mcp-read-title">Test live MCP read access</h2>
                    <p>Verify tool discovery, overview and blog-post listing over the actual HTTPS endpoint.</p>
                </div>
            </div>
            <div class="mcp-panel__body">
                @if($qa)
                    <form class="mcp-form" method="POST" action="{{ route('developer.mcp.check') }}">
                        @csrf
                        <div class="mcp-field">
                            <label class="mcp-field__label" for="mcp-check-password">Administrator password</label>
                            <input class="mcp-input" id="mcp-check-password" name="password" type="password"
                                autocomplete="current-password" placeholder="Enter your administrator password" required>
                        </div>
                        <p class="mcp-helper"><x-icon name="shield-check" size="16"/> Uses a temporary, read-only credential that is revoked after the check.</p>
                        <div class="mcp-form__actions">
                            <button type="submit" class="btn primary compact">
                                <x-icon name="play" size="16"/> Run live read-only check
                            </button>
                        </div>
                    </form>
                @else
                    <p class="mcp-helper">This action is available only in the isolated QA environment at <code>https://oauth-qa.bambodia.com</code>.</p>
                @endif
            </div>
        </section>

        <section class="panel mcp-panel mcp-panel--action" aria-labelledby="mcp-write-title">
            <div class="mcp-panel__head">
                <div class="mcp-step" aria-hidden="true">03</div>
                <div class="mcp-panel__heading">
                    <h2 id="mcp-write-title">Create a test draft</h2>
                    <p>Create one unpublished QA blog post through the real MCP write tool and verify it in Post Manager.</p>
                </div>
            </div>
            <div class="mcp-panel__body">
                @if($qa)
                    <form class="mcp-form" method="POST" action="{{ route('developer.mcp.create-draft') }}">
                        @csrf
                        <div class="mcp-field">
                            <label class="mcp-field__label" for="mcp-write-password">Administrator password</label>
                            <input class="mcp-input" id="mcp-write-password" name="password" type="password"
                                autocomplete="current-password" placeholder="Enter your administrator password" required>
                        </div>
                        <label class="mcp-consent" for="mcp-write-confirm">
                            <input id="mcp-write-confirm" type="checkbox" name="confirm_test_draft" value="1" required>
                            <span>I approve creating one <strong>unpublished QA draft</strong> for verification in Post Manager.</span>
                        </label>
                        <div class="mcp-form__actions">
                            <button class="btn primary compact" type="submit">
                                <x-icon name="file-plus" size="16"/> Create QA draft through MCP
                            </button>
                        </div>
                    </form>
                @else
                    <p class="mcp-helper">QA draft creation is unavailable outside the isolated QA environment.</p>
                @endif
            </div>
        </section>
    </div>

    <section class="panel mcp-panel" aria-labelledby="mcp-verify-title">
        <div class="mcp-panel__head">
            <div class="mcp-step" aria-hidden="true">04</div>
            <div class="mcp-panel__heading">
                <h2 id="mcp-verify-title">Verify the ChatGPT connection</h2>
                <p>After authorizing ChatGPT with OAuth, compare real tool results with the Bambodia administrator dashboard.</p>
            </div>
            <a class="btn subtle compact mcp-panel__header-action" href="{{ route('developer.oauth.index') }}">
                <x-icon name="shield" size="15"/> Manage access
            </a>
        </div>
        <div class="mcp-panel__body">
            <ol class="mcp-checklist">
                <li><span class="mcp-checklist__marker">1</span><div><strong>Discover</strong><span>Ask ChatGPT to show Bambodia Manager's available tools.</span></div></li>
                <li><span class="mcp-checklist__marker">2</span><div><strong>Read</strong><span>List the latest Bambodia blog posts through MCP.</span></div></li>
                <li><span class="mcp-checklist__marker">3</span><div><strong>Create</strong><span>Approve an unpublished test post draft through the connected ChatGPT session.</span></div></li>
                <li><span class="mcp-checklist__marker">4</span><div><strong>Verify</strong><span>Open Post Manager and confirm the new draft matches.</span></div></li>
                <li><span class="mcp-checklist__marker">5</span><div><strong>Revoke</strong><span>Remove the OAuth connection, then confirm further calls are rejected.</span></div></li>
            </ol>
            <div class="mcp-panel__footer mcp-panel__footer--last">
                <p class="mcp-helper"><x-icon name="info" size="15"/> These QA actions do not authorize publication, payment, uploads or changes to production.</p>
                <a class="btn subtle compact" href="{{ route('developer.docs') }}"><x-icon name="book" size="15"/> API documentation</a>
            </div>
        </div>
    </section>
</div>
@endsection
