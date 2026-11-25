@extends('layouts.workspace')
@section('workspace', $workspace)
@section('title', 'Project Tracking')
@push('head')
<link rel="stylesheet" href="{{ asset('css/project-tracking.css') }}?v=1">
@endpush
@section('content')
<div class="container inner-page project-tracking">
  <div class="tracking-page-head">
    <div>
      <div class="eyebrow">PRIVATE CLIENT WORKSPACE</div>
      <h1>Project tracking</h1>
      <p>{{ $workspace === 'creator' ? 'Manage purchased projects, service work and freelance deliveries with your customers.' : 'Track purchases, share requirements, review work and communicate privately with creators.' }}</p>
    </div>
    <a class="btn subtle" href="{{ $workspace === 'creator' ? route('studio.orders.index') : route('account.orders.index') }}">View order history</a>
  </div>
  @php
    $activeCount = $orders->getCollection()->filter(fn($order) =>
      in_array($order->serviceInquiry?->deliveryStatus(), ['awaiting_requirements','in_progress','revision_requested'], true))->count();
    $reviewCount = $orders->getCollection()->filter(fn($order) => $order->serviceInquiry?->deliveryStatus() === 'delivered')->count();
  @endphp
  <div class="tracking-summary-grid">
    <div class="tracking-summary"><span><x-icon name="briefcase" size="18"/> Verified orders</span><strong>{{ number_format($orders->total()) }}</strong><small>Matching purchased workspaces</small></div>
    <div class="tracking-summary"><span><x-icon name="clock" size="18"/> Active on this page</span><strong>{{ $activeCount }}</strong><small>Awaiting brief or being prepared</small></div>
    <div class="tracking-summary"><span><x-icon name="clipboard-check" size="18"/> Awaiting review on this page</span><strong>{{ $reviewCount }}</strong><small>Deliveries ready for customer approval</small></div>
  </div>
  <section class="tracking-panel">
    <div class="tracking-panel-heading">
      <div><h2>Orders and deliverables</h2><p>Only paid, verified Project, Service and Freelance orders are shown. Filters update this list.</p></div>
    </div>
    <form method="get" action="{{ $workspace === 'creator' ? route('studio.tracking.index') : route('account.tracking.index') }}" class="tracking-filters">
      <label>Find a project <input name="q" value="{{ $search }}" maxlength="120" placeholder="Order ID or listing title"></label>
      <label>Listing type <select name="type">
        @foreach(['all'=>'All types','service'=>'Services','project'=>'Projects','freelance'=>'Freelance'] as $key=>$label)
          <option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option>
        @endforeach
      </select></label>
      <label>Progress <select name="status">
        @foreach(['all'=>'All stages','awaiting_requirements'=>'Awaiting requirements','in_progress'=>'In progress',
          'delivered'=>'Awaiting review','revision_requested'=>'Revision requested','accepted'=>'Accepted'] as $key=>$label)
          <option value="{{ $key }}" @selected($status === $key)>{{ $label }}</option>
        @endforeach
      </select></label>
      <button class="btn primary" type="submit">Apply filters</button>
      <a class="btn subtle" href="{{ $workspace === 'creator' ? route('studio.tracking.index') : route('account.tracking.index') }}">Reset</a>
    </form>
    <div class="tracking-order-grid">
      @forelse($orders as $order)
        @php
          $delivery = $order->serviceInquiry;
          $stage = $delivery?->deliveryStatus() ?? 'awaiting_requirements';
          $percent = match($stage) {
            'awaiting_requirements' => 10, 'in_progress' => 45, 'revision_requested' => 65,
            'delivered' => 85, 'accepted' => 100, default => 0,
          };
          $typeLabel = ucfirst($listingTypes[$order->marketplace_listing_id] ?? 'Service');
        @endphp
        <article class="tracking-order-card">
          <div class="tracking-order-top">
            <span class="tracking-kind">{{ $typeLabel }}</span>
            <span class="tracking-state tracking-state-{{ $stage }}">{{ \App\Models\ServiceInquiry::DELIVERY_STATUSES[$stage] ?? ucfirst($stage) }}</span>
          </div>
          <h3><a href="{{ route('tracking.orders.show', $order->reference()) }}">{{ $order->listing_title }}</a></h3>
          <p class="tracking-subtle">Order #{{ $order->reference() }} · {{ $workspace === 'creator' ? $order->buyer_name : $order->seller_name }}</p>
          <div class="tracking-progress-label"><span>Workflow stage</span><strong>{{ $percent }}%</strong></div>
          <progress value="{{ $percent }}" max="100">{{ $percent }}%</progress>
          <div class="tracking-card-footer">
            <span>{{ $order->currency }} {{ number_format((float) $order->total, 2) }} gross order total</span>
            <a class="btn primary compact" href="{{ route('tracking.orders.show', $order->reference()) }}">Open tracking</a>
          </div>
        </article>
      @empty
        <div class="tracking-empty">
          <x-icon name="folder-open" size="30"/>
          <h3>No matching verified orders</h3>
          <p>When a Project, Service or Freelance purchase has a confirmed payment and a delivery workspace, it appears here. Product downloads and unverified payments are excluded.</p>
          <a class="btn subtle" href="{{ $workspace === 'creator' ? route('studio.orders.index') : route('account.orders.index') }}">Open order history</a>
        </div>
      @endforelse
    </div>
    <x-pagination :paginator="$orders"/>
  </section>
  @if($contracts->isNotEmpty())
    <section class="tracking-panel">
      <div class="tracking-panel-heading"><div><h2>Freelance agreements</h2>
        <p>Existing contract milestones and approvals have their own private legal and delivery workflow. These are not automatically funded by a marketplace order.</p></div>
        <a class="btn subtle compact" href="{{ route('contracts.index', $workspace === 'creator' ? ['workspace'=>'creator'] : []) }}">View contracts</a>
      </div>
      <div class="tracking-contract-list">
        @foreach($contracts as $contract)
          @php $percent = $contract->milestones_count ? (int)round(100 * $contract->accepted_milestones_count / $contract->milestones_count) : 0; @endphp
          <a href="{{ route('contracts.show', $contract) }}" class="tracking-contract-row">
            <span class="tracking-contract-title"><strong>{{ $contract->title }}</strong><small>{{ ucfirst($contract->status) }}</small></span>
            <span class="tracking-contract-progress"><progress value="{{ $percent }}" max="100"></progress><small>{{ $contract->accepted_milestones_count }}/{{ $contract->milestones_count }} milestones accepted</small></span>
            <span>View agreement</span>
          </a>
        @endforeach
      </div>
    </section>
  @endif
</div>
@endsection
