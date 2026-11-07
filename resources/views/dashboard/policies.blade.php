@extends('layouts.dashboard')
@section('title','Policy Center')
@section('content')
<div class="page-intro">
  <div class="eyebrow">Content &amp; Policies</div>
  <h1>Policy Center</h1>
  <p>Manage legal content, publish reviewed revisions, and communicate policy updates.</p>
</div>
<section class="panel">
  <div class="panel-head">
    <div><h2>Policies <span class="count">{{ $policies->total() }}</span></h2><p class="text-muted">Every legal draft opens in the full Policy Builder.</p></div>
    <div class="toolbar-actions">
      <form method="post" action="{{ route('dashboard.policies.legal-drafts') }}" onsubmit="return confirm('Create any missing legal drafts? Published policies and existing edits will remain untouched.')">
        @csrf <button class="btn subtle" type="submit"><x-icon name="file-check" size="16"/> Create legal drafts</button>
      </form>
      <form method="post" action="{{ route('dashboard.policies.supplemental-drafts') }}" onsubmit="return confirm('Create seven additional draft policies? No existing policies will change or be published.')">
        @csrf <button class="btn subtle" type="submit"><x-icon name="file-text" size="16"/> Add compliance drafts</button>
      </form>
      <a class="btn primary" href="{{ route('dashboard.policies.create') }}"><x-icon name="edit" size="16"/> New policy</a>
    </div>
  </div>
  <div class="table-scroll">
    <table class="data-table">
      <thead><tr><th>Policy ID</th><th>Title</th><th>Category</th><th>Version</th><th>Status</th><th>Reviewed</th><th>Updated</th><th>Action</th></tr></thead>
      <tbody>
        @forelse($policies as $policy)
          <tr>
            <td class="mono">{{ $policy->public_code }}</td>
            <td><a href="{{ route('dashboard.policies.edit',$policy) }}"><strong>{{ $policy->title }}</strong></a></td>
            <td>{{ ucfirst($policy->category) }}</td>
            <td class="mono">v{{ $policy->version }}</td>
            <td><span class="role-pill">{{ ucfirst($policy->status) }}</span></td>
            <td>{{ $policy->reviewed_at?->format('d M Y') ?? 'Pending' }}</td>
            <td>{{ $policy->updated_at?->format('d M Y') ?? '—' }}</td>
            <td>
              <div class="toolbar-actions">
                <a class="btn subtle compact" href="{{ route('dashboard.policies.edit',$policy) }}"><x-icon name="edit" size="15"/> Edit</a>
                <a class="btn subtle compact" href="{{ route('dashboard.policies.preview',$policy) }}"><x-icon name="eye" size="15"/> Preview</a>
              </div>
            </td>
          </tr>
        @empty
          <tr><td colspan="8" class="empty-cell">No policies have been created. Select Create legal drafts or New policy.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
  <x-pagination :paginator="$policies"/>
</section>
@endsection
