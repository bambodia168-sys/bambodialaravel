@extends('layouts.main')
@section('title',$license->name)
@section('content')
<div class="container inner-page">
  <article class="panel editorial-page">
    <div class="eyebrow">Bambodia · Digital Product License</div>
    <h1>{{ $license->name }}</h1>
    <p class="text-muted">{{ $license->tierLabel() }} · Code {{ $license->code }} · Version {{ $license->version }} · Published {{ $license->published_at?->format('d M Y') }}</p>
    <p>{{ $license->tier === 'extended' ? \App\Models\LicenseDefinition::tierDescription('extended') : \App\Models\LicenseDefinition::tierDescription('regular') }}</p>
    <h2>Licensed permissions</h2>
    <div class="policy-copy">{!! nl2br(e($license->permissions)) !!}</div>
    <h2>Restrictions</h2>
    <div class="policy-copy">{!! nl2br(e($license->restrictions)) !!}</div>
    <p class="hint">Rights apply according to the published license text for the relevant product. The marketplace has not enabled checkout or license delivery.</p>
  </article>
</div>
@endsection
