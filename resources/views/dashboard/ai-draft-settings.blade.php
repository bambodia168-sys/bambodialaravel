@extends('layouts.dashboard')
@section('title','AI Draft Studio')
@section('content')
<div class="page-intro">
  <div class="eyebrow">Appearance &amp; Content / AI assistance</div>
  <h1>AI Draft Studio</h1>
  <p>Connect an approved model for private Builder suggestions. Drafts always require creator review and the existing submission workflow; no content is published automatically.</p>
</div>

<div class="metrics">
  <div class="metric"><span><x-icon name="sparkles" size="18"/> Provider status</span>
    <strong style="font-size:20px">{{ $provider?->is_enabled ? 'Enabled' : 'Disabled' }}</strong>
    <small>{{ $provider?->api_key ? 'Encrypted credential stored' : 'No provider key connected' }}</small>
  </div>
  <div class="metric"><span><x-icon name="activity" size="18"/> Requests today</span>
    <strong>{{ number_format($usageToday) }}</strong>
    <small>{{ number_format($completedToday) }} completed · all attempted calls count toward limits</small>
  </div>
  <div class="metric"><span><x-icon name="check-circle" size="18"/> Reviewed today</span>
    <strong>{{ number_format($reviewedToday) }}</strong>
    <small>Applied in local Builder only; submission remains manual</small>
  </div>
</div>

<section class="panel" style="margin-top:20px">
  <div class="panel-head">
    <div>
      <h2>Provider configuration</h2>
      <p>Only super-admins can manage the provider. Credentials are encrypted and never displayed after saving.</p>
    </div>
    <button class="btn primary compact" type="button" data-modal-open="ai-provider-config">
      <x-icon name="settings" size="16"/> Configure
    </button>
  </div>
  <div style="padding:18px 24px;display:grid;gap:12px">
    <div><strong>Provider:</strong> {{ $provider?->provider === 'openai' ? 'OpenAI Responses API' : 'Not connected' }}</div>
    <div><strong>Model:</strong> {{ $models[$provider?->model] ?? 'Not selected' }}</div>
    <div><strong>Latest successful test:</strong>
      {{ $provider?->verified_at?->format('d M Y H:i').' UTC' ?? 'Not tested' }}
    </div>
    <div><strong>Per-account daily limit:</strong> {{ $provider?->daily_limit ?? 5 }} requests</div>
    <div><strong>Platform daily limit:</strong> {{ $provider?->global_daily_limit ?? 100 }} requests</div>
    <p class="hint">All limits use the server's date and include attempted/failed requests to prevent uncontrolled costs. The connection is off until a real provider test succeeds and you explicitly enable it.</p>
    <div class="dialog-actions" style="justify-content:flex-end;flex-wrap:wrap">
      @if($provider?->api_key)
        <form method="post" action="{{ route('dashboard.ai-draft.test') }}">
          @csrf
          <button class="btn subtle" type="submit"><x-icon name="refresh-cw" size="16"/> Test connection</button>
        </form>
        <form method="post" action="{{ route('dashboard.ai-draft.status') }}">
          @csrf @method('PATCH')
          <input type="hidden" name="is_enabled" value="{{ $provider?->is_enabled ? '0' : '1' }}">
          <button class="btn primary" type="submit"><x-icon name="shield-check" size="16"/>
            {{ $provider?->is_enabled ? 'Disable AI drafting' : 'Enable AI drafting' }}</button>
        </form>
        <button class="btn subtle" type="button" data-modal-open="ai-provider-disconnect">
          <x-icon name="trash-2" size="16"/> Disconnect
        </button>
      @endif
    </div>
  </div>
</section>

<section class="panel" style="margin-top:20px">
  <div class="panel-head">
    <div><h2>AI generation activity <span class="count">{{ $usage->total() }}</span></h2>
      <p>Metadata only: creator, content type, status and request counts. Raw prompts, generated text and API keys are not stored here.</p>
    </div>
  </div>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Time</th><th>Creator</th><th>Type</th><th>Model</th><th>Status</th><th>Human review</th></tr></thead>
      <tbody>
        @forelse($usage as $request)
          <tr>
            <td>{{ $request->created_at->format('d M Y H:i') }} UTC</td>
            <td><strong>{{ $request->author?->name ?? 'Deleted account' }}</strong></td>
            <td>{{ ucfirst($request->content_type) }}</td>
            <td>{{ $request->model }}</td>
            <td><span class="role-pill">{{ ucfirst($request->status) }}</span></td>
            <td>{{ $request->reviewed_at ? $request->reviewed_at->format('d M Y H:i').' UTC' : 'Not reviewed' }}</td>
          </tr>
        @empty
          <tr><td colspan="6" class="empty-cell">No AI drafting requests yet. Enable a tested provider to allow creators to generate drafts.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <x-pagination :paginator="$usage"/>
</section>

<dialog class="config-dialog wide-dialog" id="ai-provider-config">
  <form method="post" action="{{ route('dashboard.ai-draft.save') }}" autocomplete="off">
    @csrf
    <h2>Configure AI Draft Studio</h2>
    <p>Connect the official OpenAI Responses API. API key changes or a different model automatically disable generation until retested.</p>
    <label for="ai-model">Model</label>
    <select id="ai-model" name="model" required>
      @foreach($models as $id => $label)
        <option value="{{ $id }}" @selected(($provider?->model??'gpt-4.1-mini')===$id)>{{ $label }}</option>
      @endforeach
    </select>
    <label for="ai-api-key">OpenAI API key</label>
    <input id="ai-api-key" type="password" name="api_key" minlength="20" maxlength="500"
      placeholder="{{ $provider?->api_key ? 'Leave blank to keep encrypted key' : 'Paste private provider key' }}"
      autocomplete="new-password">
    <label for="ai-user-limit">Requests per account per day</label>
    <input id="ai-user-limit" name="daily_limit" type="number" min="1" max="30"
      value="{{ $provider?->daily_limit ?? 5 }}" required>
    <label for="ai-global-limit">Total requests per day (all accounts)</label>
    <input id="ai-global-limit" name="global_daily_limit" type="number" min="1" max="200"
      value="{{ $provider?->global_daily_limit ?? 100 }}" required>
    <p class="hint">Provider calls may incur charges. This integration uses a fixed HTTPS API endpoint and never exposes the secret to browsers. Creators must explicitly consent to sending their own brief.</p>
    <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button>
      <button class="btn primary" type="submit">Save configuration</button></div>
  </form>
</dialog>

@if($provider)
<dialog class="config-dialog wide-dialog" id="ai-provider-disconnect">
  <form method="post" action="{{ route('dashboard.ai-draft.disconnect') }}">
    @csrf @method('DELETE')
    <h2>Disconnect AI provider?</h2>
    <p>Disables all AI drafting and removes the encrypted key. Metadata-only usage history is retained; existing Builder content remains unchanged.</p>
    <div class="dialog-actions">
      <button type="button" class="btn subtle" data-modal-close>Cancel</button>
      <button type="submit" class="btn primary">Disconnect provider</button>
    </div>
  </form>
</dialog>
@endif
@endsection
