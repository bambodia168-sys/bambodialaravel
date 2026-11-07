@extends('layouts.dashboard')
@section('title','Contract Manager')
@section('content')
<div class="page-intro">
  <div class="eyebrow">Freelance operations · Contract governance</div>
  <h1>Contract Manager</h1>
  <p>Monitor agreement states and account-verified milestones. Ordinary contract scope and private messages remain available only to the participants; reported disputes have a separate, audited review process.</p>
</div>
<section class="panel">
  <div class="panel-head">
    <h2>Recorded agreements <span class="count">{{ $contracts->total() }}</span></h2>
    <div class="toolbar-actions">
      <form class="table-search" method="get">
        <select class="table-select" name="status" aria-label="Filter agreement state">
          @foreach(['all','draft','offered','active','disputed','completed','declined','cancelled'] as $option)
            <option value="{{ $option }}" @selected($option===$status)>{{ ucfirst($option) }}</option>
          @endforeach
        </select>
        <button class="btn subtle" type="submit">Filter</button>
      </form>
      <a class="btn subtle" href="{{ route('dashboard.contracts.disputes') }}">Review disputes</a>
    </div>
  </div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>Reference</th><th>Agreement</th><th>Client</th><th>Freelancer</th><th>Milestones</th><th>State</th><th>Action</th></tr></thead>
    <tbody>
      @forelse($contracts as $contract)
      <tr>
        <td class="mono">{{ $contract->public_id }}</td>
        <td><strong>{{ $contract->title }}</strong><div class="text-muted">{{ $contract->created_at->format('d M Y') }}</div></td>
        <td>{{ $contract->client?->name }}</td>
        <td>{{ $contract->freelancer?->name }}</td>
        <td>{{ $contract->milestones_count }}</td>
        <td><span class="role-pill">{{ ucfirst($contract->status) }}</span></td>
        <td><x-row-action-menu :id="'contract-'.$contract->id" :label="$contract->title">
            <button type="button" data-modal-open="admin-contract-{{ $contract->id }}"><x-icon name="settings" size="16"/> Configure</button>
          </x-row-action-menu>
          <dialog class="config-dialog" id="admin-contract-{{ $contract->id }}">
            <h2>{{ $contract->title }}</h2>
            <p>Reference {{ $contract->public_id }} · {{ ucfirst($contract->status) }}</p>
            <p><strong>Client:</strong> {{ $contract->client?->name }} ({{ $contract->client?->public_id }})</p>
            <p><strong>Freelancer:</strong> {{ $contract->freelancer?->name }} ({{ $contract->freelancer?->public_id }})</p>
            <p><strong>Milestones:</strong> {{ $contract->milestones_count }}</p>
            <p><strong>Proposed value:</strong> USD {{ number_format((float)$contract->agreed_budget,2) }} — no payment processed</p>
            <p class="hint">Administrator metadata access does not authorize opening a private contract or changing its terms.</p>
            <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Close</button>
              @if($contract->dispute)
                <a class="btn primary" href="{{ route('dashboard.contracts.inspect',$contract->dispute) }}">Review reported dispute</a>
              @endif
            </div>
          </dialog>
        </td>
      </tr>
      @empty
        <tr><td colspan="7" class="empty-cell">No agreements match the selected state.</td></tr>
      @endforelse
    </tbody>
  </table></div><x-pagination :paginator="$contracts"/>
</section>
<section class="panel">
  <div class="panel-head"><h2>Agreement state counts</h2></div>
  <div class="table-scroll"><table class="data-table">
    <thead><tr><th>State</th><th>Recorded agreements</th></tr></thead>
    <tbody>
    @forelse($statusCounts as $name=>$count)
      <tr><td>{{ ucfirst($name) }}</td><td>{{ number_format($count) }}</td></tr>
    @empty <tr><td colspan="2" class="empty-cell">No agreements yet.</td></tr> @endforelse
    </tbody>
  </table></div>
</section>
@endsection
