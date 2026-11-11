@extends('layouts.workspace')
@section('workspace','developer')
@section('title','OAuth Connections')
@section('content')
<div class="page-intro">
  <div class="eyebrow">Integrations & Credentials</div>
  <h1>OAuth Connections</h1>
  <p>Review and revoke Bambodia Manager connections authorized for your account. Revoking a connection also revokes its associated refresh token.</p>
</div>
<div class="workspace-quick-actions">
  <a href="{{ route('developer.index') }}" class="btn subtle compact"><x-icon name="arrow-left" size="16"/> Developer Dashboard</a>
</div>
<section class="panel">
 <div class="panel-head"><h2>MCP OAuth access tokens <span class="count">{{ $tokens->total() }}</span></h2></div>
 <div class="table-wrap">
  <table>
   <thead><tr><th>Application</th><th>Scopes</th><th>Issued</th><th>Expires</th><th>Status</th><th class="text-right">Action</th></tr></thead>
   <tbody>
   @forelse ($tokens as $token)
     <tr>
       <td>{{ $token->client?->name ?: 'OAuth client' }}</td>
       <td>{{ implode(', ', $token->scopes ?? []) }}</td>
       <td>{{ $token->created_at?->format('Y-m-d H:i') ?: '—' }}</td>
       <td>{{ $token->expires_at?->format('Y-m-d H:i') ?: '—' }}</td>
       <td>{{ $token->revoked ? 'Revoked' : ($token->expires_at?->isPast() ? 'Expired' : 'Active') }}</td>
       <td>
         @unless ($token->revoked)
         <form action="{{ route('developer.oauth.revoke', $token->getKey()) }}" method="POST" class="inline-form">
           @csrf @method('DELETE')
           <label>Confirm password <input name="password" type="password" autocomplete="current-password" required></label>
           <button type="submit" class="btn subtle compact"><x-icon name="shield" size="14"/> Revoke</button>
         </form>
         @endunless
       </td>
     </tr>
   @empty
     <tr><td colspan="6" class="hint">No Bambodia MCP OAuth connections yet.</td></tr>
   @endforelse
   </tbody>
  </table>
 </div>
 <div class="pagination-wrap">{{ $tokens->links() }}</div>
 <form method="GET" action="{{ route('developer.oauth.index') }}" class="inline-form">
   <label for="oauth-go-page">Go To page</label>
   <input id="oauth-go-page" type="number" name="page" min="1" max="{{ max(1, $tokens->lastPage()) }}" value="{{ $tokens->currentPage() }}" style="width:90px">
   <button class="btn subtle compact" type="submit">Go</button>
 </form>
</section>
@endsection
