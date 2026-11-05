@extends('layouts.workspace')
@section('workspace','developer')
@section('title','Developer API Documentation')
@section('content')
<div class="page-intro"><div class="eyebrow">Integrations & Credentials</div><h1>API documentation</h1><p>Authenticated, read-only Bambodia API endpoints currently implemented in the codebase.</p></div>
<section class="panel api-docs">
  <div class="panel-head"><div><h2>Authentication</h2><p>Tokens are created in the Developer Dashboard and expire after 7, 30 or 90 days.</p></div><a class="btn subtle" href="{{ route('developer.index') }}">Manage tokens</a></div>
  <div class="api-docs-body">
    <p>Send the token using an HTTPS request header:</p>
    <pre><code>Authorization: Bearer YOUR_BAMBODIA_ACCESS_TOKEN
Accept: application/json</code></pre>
    <p>Only <strong>Super Admin</strong> accounts can issue credentials. Passwords, secret token hashes, and unpublished listings are never returned.</p>
    <h3>GET /api/v1/account</h3>
    <p>Scope: <code>account.read</code>. Returns the account's public ID, name, email, role and selected language.</p>
    <h3>GET /api/v1/listings</h3>
    <p>Scope: <code>listings.read</code>. Returns a read-only list of published marketplace records, limited to 10 per page. Use <code>?page=2</code> for subsequent pages.</p>
    <h3>Responses</h3>
    <p><code>200</code> success, <code>401</code> missing/invalid/expired/revoked token, <code>403</code> missing permission, <code>429</code> rate limit exceeded.</p>
    <p>The API is limited to 30 requests per minute per throttle key. Requests must use HTTPS in real deployments. Never embed access tokens in frontend source, browser URLs or shared documentation.</p>
  </div>
</section>
@endsection
