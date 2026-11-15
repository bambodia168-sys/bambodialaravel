@extends('layouts.main')
@section('title',$license->name)
@section('content')
<link rel="stylesheet" href="{{ asset('css/license-manager.css') }}?v=2">
<div class="container inner-page license-reading">
  <nav class="panel license-directory" aria-labelledby="license-directory-heading">
    <h2 id="license-directory-heading">License directory</h2>
    <ol class="license-directory-list" role="list">
      @foreach($directory as $definition)
        <li>
          <a href="{{ $definition->publicUrl() }}"@if($definition->id === $license->id) aria-current="page"@endif>
            <span class="license-directory-number" aria-hidden="true">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
            <span>{{ $definition->name }}</span>
          </a>
        </li>
      @endforeach
    </ol>
  </nav>

  <article class="panel editorial-page">
    <div class="eyebrow">Bambodia · Digital Product License</div>
    <h1>{{ $license->name }}</h1>
    <p class="text-muted">{{ $license->tierLabel() }} · Code {{ $license->code }} · Version {{ $license->version }} · Published {{ $license->published_at?->format('d M Y') }}</p>
    <p>{{ $license->tier ? \App\Models\LicenseDefinition::tierDescription($license->tier) : 'This definition predates license-type classification. Its published permissions and restrictions govern actual usage rights.' }}</p>
    <h2>Licensed permissions</h2>
    <div class="policy-copy license-terms-copy">{!! $license->permissionsMarkup() !!}</div>
    <h2>Restrictions</h2>
    <div class="policy-copy license-terms-copy">{!! $license->restrictionsMarkup() !!}</div>
    <p class="hint">Rights apply according to the published license text for the relevant product. The marketplace has not enabled checkout or license delivery.</p>
  </article>
</div>
@endsection
