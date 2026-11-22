@extends('layouts.workspace')
@section('workspace', 'admin')
@section('title', 'Email Templates')
@push('head')
<link rel="stylesheet" href="{{ asset('css/system-configuration.css') }}?v=4">
<link rel="stylesheet" href="{{ asset('css/email-configuration.css') }}?v=2">
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
        <label class="email-filter-label" for="email-template-search">Search templates</label>
        <input id="email-template-search" class="table-select" name="q" maxlength="100" value="{{ $search }}" placeholder="Search templates">
        <label class="email-filter-label" for="email-template-category">Category</label>
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
          @forelse($templates as $event => $template)
            <tr>
              <td><strong>{{ $template['label'] }}</strong><p>{{ $template['subject'] }}</p></td>
              <td>{{ $template['category'] }}</td>
              <td>
                @if($event === \App\Support\EmailTemplates::VERIFY)
                  <span class="role-pill">Required</span>
                @elseif($template['category'] === 'Workflow required')
                  <span class="role-pill">Draft only</span>
                @else
                  @include('dashboard.system-toggle', ['action' => route('dashboard.email-templates.status', $event), 'enabled' => $template['enabled'], 'label' => $template['label']])
                @endif
              </td>
              <td>
                <a class="icon-button" href="{{ route('dashboard.email-templates.edit', $event) }}" aria-label="Configure {{ $template['label'] }}">
                  <x-icon name="settings" size="18"/>
                </a>
              </td>
            </tr>
          @empty
            <tr><td colspan="4" class="empty-cell">No templates match your search.</td></tr>
          @endforelse
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

</div>
@endsection
