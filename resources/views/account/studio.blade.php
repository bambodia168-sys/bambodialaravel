@extends('layouts.main')
@section('title','Creator Studio')
@section('content')
<div class="container inner-page"><div class="page-intro"><div class="eyebrow">Creator workspace</div><h1>Creator Studio</h1><p>Welcome, {{ $user->name }}. Your creator ID is {{ $user->public_id }}.</p></div>
@if(Route::has('studio.listings'))<a class="btn primary" href="{{ route('studio.listings') }}">Manage listings</a>@endif
</div>
@endsection
