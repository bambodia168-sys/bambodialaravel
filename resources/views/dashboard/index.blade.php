@extends('layouts.dashboard')
@section('title','Overview')
@section('content')
<div class="page-intro"><div class="eyebrow">Live platform data</div><h1>Dashboard overview</h1><p>Registered accounts by current role. Data updates directly from the database.</p></div>
<div class="metrics">
  <div class="metric"><span><x-icon name="users"/> Total users</span><strong>{{ number_format($totalUsers) }}</strong></div>
  <div class="metric"><span><x-icon name="briefcase"/> Freelancers</span><strong>{{ number_format($creators) }}</strong></div>
  <div class="metric"><span><x-icon name="user"/> Customers</span><strong>{{ number_format($customers) }}</strong></div>
  @if(in_array(auth()->user()->role, ['super_admin','admin'], true))
  <div class="metric"><span><x-icon name="user-check"/> Pending creator applications</span><strong>{{ number_format($creatorApplications) }}</strong></div>
  @endif
</div>
@if(auth()->user()->role === 'super_admin')
<section class="panel"><div class="panel-head"><div><h2>Account administration</h2><p>Manage registered users and access roles.</p></div><a class="btn subtle" href="{{ route('dashboard.users') }}">Open User Manager</a></div></section>
@endif
@if(in_array(auth()->user()->role, ['super_admin','admin'], true))
<section class="panel"><div class="panel-head"><div><h2>Creator applications</h2><p>Review applicants before assigning creator permissions.</p></div><a class="btn subtle" href="{{ route('dashboard.creator-applications') }}">Open applications</a></div></section>
@endif
@endsection
