@extends('layouts.main')
@section('title','Creator Studio')
@section('content')
<div class="container inner-page"><div class="page-intro"><div class="eyebrow">Creator workspace</div><h1>Creator Studio</h1><p>Manage your marketplace submissions and their publication status.</p></div>
<a class="btn primary" href="{{ route('studio.listings') }}"><x-icon name="layers" size="17"/> Manage listings</a>
</div>
@endsection
