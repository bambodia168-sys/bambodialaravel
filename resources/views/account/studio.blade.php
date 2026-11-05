@extends('layouts.main')
@section('title','Creator Studio')
@section('content')
<div class="container inner-page"><div class="page-intro"><div class="eyebrow">Creator workspace</div><h1>Creator Studio</h1><p>Manage your marketplace submissions and their publication status.</p></div>
<div class="studio-actions">
<a class="btn primary" href="{{ route('studio.products.index') }}"><x-icon name="package" size="17"/> Product Manager</a>
<a class="btn subtle" href="{{ route('studio.media.index') }}"><x-icon name="image" size="17"/> Media Library</a>
<a class="btn subtle" href="{{ route('studio.listings') }}"><x-icon name="layers" size="17"/> Projects, freelance and services</a>
</div>
</div>
@endsection
