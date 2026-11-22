@extends('layouts.main')
@section('title', 'Marketplace email preferences')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><h1>Marketplace email preferences</h1></div>
  <section class="panel">
    <div class="panel-head">
      @if($action)
        <p>Unsubscribe from listing promotions and marketplace announcements.</p>
        <form method="post" action="{{ $action }}">
          @csrf
          <button type="submit" class="btn primary">Unsubscribe</button>
        </form>
      @else
        <p>You’re unsubscribed from marketplace promotions and announcements.</p>
        <a class="btn subtle" href="{{ route('home') }}">Bambodia home</a>
      @endif
    </div>
  </section>
</div>
@endsection
