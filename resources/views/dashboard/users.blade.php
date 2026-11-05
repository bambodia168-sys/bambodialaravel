@extends('layouts.dashboard')
@section('title','User Manager')
@section('content')
<div class="page-intro"><div class="eyebrow">Access & People</div><h1>User Manager</h1><p>Search accounts, inspect public IDs, and manage access permissions.</p></div>
<section class="panel">
  <div class="panel-head"><h2>Users <span class="count">{{ $users->total() }}</span></h2>
    <form method="get" class="table-search"><x-icon name="search" size="17"/><input type="search" name="search" value="{{ $search }}" placeholder="Name, email or ID" aria-label="Search users"><button class="btn subtle" type="submit">Search</button></form>
  </div>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Public ID</th><th>Name</th><th>Email</th><th>Role</th><th>Joined</th><th>Action</th></tr></thead>
  <tbody>
    @forelse($users as $user)
    <tr><td class="mono">{{ $user->public_id }}</td><td><strong>{{ $user->name }}</strong></td><td>{{ $user->email }}</td><td><span class="role-pill">{{ str_replace('_',' ',ucwords($user->role,'_')) }}</span></td><td>{{ $user->created_at?->format('d M Y') }}</td><td>
    <button type="button" class="btn subtle compact" data-modal-open="user-{{ $user->id }}"><x-icon name="settings" size="15"/> Configure</button>
    <dialog id="user-{{ $user->id }}" class="config-dialog">
      <form method="post" action="{{ route('dashboard.users.update',$user) }}">
        @csrf @method('PATCH')
        <div class="dialog-heading"><h2>Manage {{ $user->name }}</h2><p>Public ID {{ $user->public_id }}</p></div>
        <label for="role-{{ $user->id }}">Account role</label>
        <select id="role-{{ $user->id }}" name="role" required>
          @foreach($roles as $role)<option value="{{ $role }}" @selected($role === $user->role)>{{ ucwords(str_replace('_',' ',$role)) }}</option>@endforeach
        </select>
        <div class="dialog-actions"><button type="button" class="btn subtle" data-modal-close>Cancel</button><button class="btn primary" type="submit">Save role</button></div>
      </form>
    </dialog>
    </td></tr>
    @empty <tr><td colspan="6" class="empty-cell">No users match your search.</td></tr>
    @endforelse
  </tbody></table></div>
  <x-pagination :paginator="$users"/>
</section>
@endsection
