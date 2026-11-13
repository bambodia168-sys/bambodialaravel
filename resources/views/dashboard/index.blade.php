@extends('layouts.dashboard')
@section('title','Overview')
@section('content')
<div class="page-intro">
  <div class="eyebrow">Operational overview</div>
  <h1>{{ $isStaff ? 'Support operations' : 'Dashboard overview' }}</h1>
  <p>{{ $isStaff ? 'Support work that your role is permitted to manage.' : 'Live account data, moderation queues and management actions from the platform.' }}</p>
</div>

@if(!$isStaff)
  <div class="workspace-overview-grid" aria-label="Platform account totals">
    <x-workspace-metric label="Registered accounts" :value="$stats['totalUsers']" icon="users"
      :href="auth()->user()->role==='super_admin' ? route('dashboard.users') : null" action="Manage accounts"/>
    <x-workspace-metric label="Approved freelancers" :value="$stats['creators']" icon="user-round-check"
      :href="auth()->user()->role==='super_admin' ? route('dashboard.users') : null" action="Manage accounts"/>
    <x-workspace-metric label="Customers and subscribers" :value="$stats['customers']" icon="user"
      :href="auth()->user()->role==='super_admin' ? route('dashboard.users') : null" action="Manage accounts"/>
  </div>
@endif

<section class="workspace-overview-block" aria-labelledby="admin-queues-title">
  <div class="workspace-section-head">
    <div><h2 id="admin-queues-title">Queues requiring attention</h2><p>Each count links to an existing, permission-controlled management screen.</p></div>
  </div>
  <div class="workspace-overview-grid">
    @foreach($queues as $queue)
      <x-workspace-metric :label="$queue['label']" :value="$queue['count']"
        :icon="$queue['icon']" :href="route($queue['route'], $queue['params'] ?? [])" action="Manage queue"/>
    @endforeach
  </div>
</section>

<section class="panel workspace-overview-block" aria-labelledby="admin-recent-title">
  <div class="panel-head">
    <div><h2 id="admin-recent-title">{{ $isStaff ? 'Recent unresolved tickets' : 'Latest pending marketplace submissions' }}</h2>
    <p>Open an item in its existing review workspace.</p></div>
    <a class="btn subtle compact" href="{{ $isStaff ? route('dashboard.support') : route('dashboard.reviews') }}">View queue</a>
  </div>
  <div class="workspace-overview-list">
    @forelse($recent as $item)
      @php
        $itemUrl = $isStaff
          ? route('dashboard.support.show', $item->public_id)
          : ($item->type === 'product' ? route('dashboard.products.index',['status'=>'pending','search'=>$item->title]) : route('dashboard.reviews'));
      @endphp
      <a class="workspace-overview-row" href="{{ $itemUrl }}">
        <span class="workspace-overview-row-main">
          <strong>{{ $isStaff ? $item->subject : $item->title }}</strong>
          <small>{{ $isStaff ? 'Support ticket' : ucfirst($item->type).' listing' }} · {{ ucfirst(str_replace('_',' ',$item->status)) }}</small>
        </span>
        <span class="workspace-overview-row-action">Open</span>
      </a>
    @empty
      <p class="workspace-overview-empty">No items currently require review in this queue.</p>
    @endforelse
  </div>
</section>
@endsection
