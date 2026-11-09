@extends('layouts.dashboard')
@section('title','User Manager')
@section('content')
<div class="container inner-page user-manager-v2">
  <div class="page-intro user-manager-header">
    <div><div class="eyebrow">Access &amp; People</div><h1>User Manager</h1>
      <p>Manage accounts, access restrictions and marketplace ownership. Sensitive changes are audited.</p></div>
    <a href="{{ route('dashboard.users.create') }}" class="btn primary"><x-icon name="plus" size="17"/> Create new user</a>
  </div>

  <section class="panel">
    <div class="panel-head user-manager-toolbar">
      <h2>Accounts <span class="count">{{ $users->total() }}</span></h2>
      <form method="get" action="{{ route('dashboard.users') }}" class="user-manager-filters" aria-label="Filter users">
        <input name="search" type="search" value="{{ $search }}" maxlength="120" placeholder="Name, username, email or ID" aria-label="Find users">
        <select name="role" aria-label="Filter by role">
          <option value="">All roles</option>
          @foreach(\App\Models\User::ROLES as $option)
            <option value="{{ $option }}" @selected($option===$role)>{{ ucwords(str_replace('_',' ',$option)) }}</option>
          @endforeach
        </select>
        <select name="status" aria-label="Filter by account status">
          <option value="">All statuses</option>
          @foreach(['active','suspended','archived'] as $option)
            <option value="{{ $option }}" @selected($option===$status)>{{ ucfirst($option) }}</option>
          @endforeach
        </select>
        <select name="sort" aria-label="Sort users">
          <option value="newest" @selected($sort==='newest')>Newest first</option>
          <option value="oldest" @selected($sort==='oldest')>Oldest first</option>
          <option value="name" @selected($sort==='name')>Name A–Z</option>
        </select>
        <button type="submit" class="btn subtle compact"><x-icon name="search" size="15"/> Search</button>
        <a class="btn subtle compact" href="{{ route('dashboard.users') }}">Reset</a>
      </form>
    </div>
    <div class="table-scroll">
      <table class="data-table user-manager-table">
        <thead><tr><th>Public ID</th><th>Name / Username</th><th>Email</th><th>Role</th><th>Status</th><th>Joined</th><th>Action</th></tr></thead>
        <tbody>
          @forelse($users as $member)
          <tr>
            <td class="mono">{{ $member->public_id }}</td>
            <td><a href="{{ route('dashboard.users.show',$member) }}" class="user-manager-primary-link">{{ $member->name }}</a>
              @if($member->username)<small class="user-manager-small">&#64;{{ $member->username }}</small>@endif</td>
            <td>{{ $member->email }}</td>
            <td><span class="role-pill">{{ ucwords(str_replace('_',' ',$member->role)) }}</span></td>
            <td><span class="user-manager-status user-manager-status--{{ $member->account_status }}">{{ ucfirst($member->account_status) }}</span></td>
            <td>{{ $member->created_at?->format('d M Y') }}</td>
            <td>
              <x-row-action-menu :id="'user-'.$member->id" :label="$member->name">
                <a href="{{ route('dashboard.users.show',$member) }}"><x-icon name="eye" size="16"/> View details</a>
                <a href="{{ route('dashboard.users.edit',$member) }}"><x-icon name="pencil" size="16"/> Edit user</a>
              </x-row-action-menu>
            </td>
          </tr>
          @empty
          <tr><td colspan="7" class="empty-cell">No accounts match these filters.</td></tr>
          @endforelse
        </tbody>
      </table>
    </div>
    <x-pagination :paginator="$users"/>
  </section>
  <p class="hint">Archiving is reversible. We preserve accounts with contract, license, payment, audit and ownership records rather than deleting their history.</p>
</div>
@endsection