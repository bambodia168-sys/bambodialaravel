@extends('layouts.workspace')
@section('workspace', 'admin')
@section('title', 'Email Templates')
@push('head')
<link rel="stylesheet" href="{{ asset('css/system-configuration.css') }}?v=4">
<link rel="stylesheet" href="{{ asset('css/email-configuration.css') }}?v=1">
@endpush
@section('content')
<div class="container inner-page email-configuration">
  <div class="page-intro">
    <div class="eyebrow">System Configuration</div>
    <h1>Email Templates</h1>
    <p>Manage the emails Bambodia sends for purchases, accounts, and marketplace activity.</p>
  </div>
  <div class="email-transport panel">
    <div>
      <strong>{{ $deliveryReady ? 'Mail transport configured' : 'Mail transport needs setup' }}</strong>
      <p>{{ $deliveryReady ? 'Use a test email to confirm delivery to your inbox.' : 'Transactional emails wait in the queue until mail delivery is configured.' }}</p>
    </div>
    <div class="email-actions">
      <a class="btn subtle compact" href="{{ route('dashboard.smtp.index') }}">SMTP Setup</a>
      <a class="btn subtle compact" href="{{ route('dashboard.email-campaigns.index') }}">Email Campaigns</a>
    </div>
  </div>
  <section class="panel">
    <div class="panel-head">
      <h2>Templates <span class="count">{{ $templates->total() }}</span></h2>
      <form class="email-actions" method="get">
        <label for="email-template-category">Category</label>
        <select id="email-template-category" name="category" class="table-select">
          <option value="">All categories</option>
          @foreach($categories as $option)<option @selected($category === $option)>{{ $option }}</option>@endforeach
        </select>
        <button class="btn subtle compact">Filter</button>
      </form>
    </div>
    <div class="table-scroll">
      <table class="data-table email-table">
        <thead><tr><th scope="col">Template</th><th scope="col">Category</th><th scope="col">Status</th><th scope="col">Configure</th></tr></thead>
        <tbody>
          @foreach($templates as $event => $template)
            @php
              $dialog = 'email-template-'.str_replace('.', '-', $event);
            @endphp
            <tr>
              <td><strong>{{ $template['label'] }}</strong><p>{{ $template['subject'] }}</p></td>
              <td>{{ $template['category'] }}</td>
              <td>@include('dashboard.system-toggle', ['action' => route('dashboard.email-templates.status', $event), 'enabled' => $template['enabled'], 'label' => $template['label']])</td>
              <td>
                <button class="icon-button" type="button" data-modal-open="{{ $dialog }}" aria-haspopup="dialog" aria-controls="{{ $dialog }}" aria-label="Configure {{ $template['label'] }}">
                  <x-icon name="settings" size="18"/>
                </button>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <x-pagination :paginator="$templates"/>
  </section>
  <section class="panel">
    <div class="panel-head">
      <div><h2>Recent delivery</h2><p>{{ $counts['queued'] ?? 0 }} queued · {{ $counts['sent'] ?? 0 }} accepted · {{ $counts['failed'] ?? 0 }} failed</p></div>
    </div>
    <div class="table-scroll">
      <table class="data-table">
        <thead><tr><th scope="col">Template</th><th scope="col">Delivery</th><th scope="col">Queued</th><th scope="col">Accepted</th><th scope="col">Action</th></tr></thead>
        <tbody>
          @forelse($deliveries as $delivery)
            <tr>
              <td>{{ \App\Support\EmailTemplates::defaults($delivery->event)['label'] ?? $delivery->event }}</td>
              <td><span class="role-pill">{{ ucfirst($delivery->status) }}</span></td>
              <td>{{ $delivery->created_at->format('d M Y H:i') }}</td>
              <td>{{ $delivery->sent_at?->format('d M Y H:i') ?? '—' }}</td>
              <td>
                @if($delivery->status === 'failed')
                  <form method="post" action="{{ route('dashboard.email-templates.retry', $delivery->id) }}">
                    @csrf
                    <button class="btn subtle compact">Retry</button>
                  </form>
                @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="5" class="empty-cell">New delivery activity will appear here.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
  </section>

  @foreach($templates as $event => $template)
    @php
      $dialog = 'email-template-'.str_replace('.', '-', $event);
      $validation = $errors->getBag($event);
      $customized = $overrides->get($event)?->subject !== null;
    @endphp
    <dialog class="config-dialog email-dialog" id="{{ $dialog }}" aria-labelledby="{{ $dialog }}-title">
      <header class="email-dialog-heading">
        <div><h2 id="{{ $dialog }}-title">{{ $template['label'] }}</h2><p>{{ $template['category'] }} · {{ $template['enabled'] ? 'On' : 'Off' }}</p></div>
        <button type="button" class="icon-button" data-modal-close aria-label="Close configuration"><x-icon name="x" size="18"/></button>
      </header>
      <form method="post" action="{{ route('dashboard.email-templates.update', $event) }}" id="{{ $dialog }}-form" class="email-edit-form">
        @csrf @method('PUT')
        @if($validation->any())
          <ul role="alert">@foreach($validation->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
        @endif
        <label>Subject<input name="subject" maxlength="180" required value="{{ $validation->any() ? old('subject') : $template['subject'] }}"></label>
        <label>Heading<input name="heading" maxlength="180" required value="{{ $validation->any() ? old('heading') : $template['heading'] }}"></label>
        <label>Message<textarea name="body" rows="7" maxlength="6000" required>{{ $validation->any() ? old('body') : $template['body'] }}</textarea></label>
        <label>Button label<input name="button" maxlength="90" value="{{ $validation->any() ? old('button') : $template['button'] }}"></label>
        <p class="email-field-help">Leave the button label empty to hide the button. The destination comes from the event.</p>
        <details class="email-variables"><summary>Personalization fields</summary>
          <p>Enter a field exactly as shown. Order fields are filled only for order emails.</p>
          <div class="email-actions">
            @foreach(\App\Support\EmailTemplates::VARIABLES as $key)<code>{{ str_repeat('{', 2).$key.str_repeat('}', 2) }}</code>@endforeach
          </div>
        </details>
      </form>
      <footer class="email-dialog-footer">
        <div class="email-actions">
          <a class="btn subtle compact" href="{{ route('dashboard.email-templates.preview', $event) }}" target="_blank" rel="noopener">Preview saved</a>
          <form method="post" action="{{ route('dashboard.email-templates.test', $event) }}">@csrf<button class="btn subtle compact" @disabled(!$deliveryReady)>Send test to me</button></form>
          @if($customized)
            <details class="email-delete">
              <summary>Delete custom copy</summary>
              <p>This restores the template’s default text.</p>
              <form method="post" action="{{ route('dashboard.email-templates.destroy', $event) }}">@csrf @method('DELETE')<button class="btn danger compact">Restore default copy</button></form>
            </details>
          @endif
        </div>
        <button type="submit" form="{{ $dialog }}-form" class="btn primary">Save template</button>
      </footer>
    </dialog>
    @if($validation->any())<script>document.getElementById(@json($dialog)).showModal();</script>@endif
  @endforeach
</div>
@endsection
