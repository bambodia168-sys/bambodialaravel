@extends('layouts.dashboard')
@section('title','License Manager')
@section('content')
<link rel="stylesheet" href="{{ asset('css/license-manager.css') }}?v=1">
<div class="page-intro license-manager-heading">
  <div>
    <div class="eyebrow">Marketplace Governance</div>
    <h1>License Manager</h1>
  </div>
  <nav class="license-manager-tabs" aria-label="License Manager sections">
    @foreach(['products' => ['key-round', 'Product Licenses'], 'renewals' => ['history', 'Support Renewals'], 'definitions' => ['file-check', 'License Definitions']] as $key => [$icon, $label])
      <a href="{{ route('dashboard.licenses.index', ['tab' => $key]) }}" @if($tab === $key) aria-current="page" @endif>
        <x-icon :name="$icon" size="16"/> {{ $label }}
      </a>
    @endforeach
  </nav>
</div>

@switch($tab)
  @case('definitions')
    @include('dashboard.partials.license-definitions')
    <script defer src="{{ asset('js/license-terms-editor.js') }}?v=1"></script>
    @break
  @case('renewals')
    @include('dashboard.partials.license-renewals')
    @break
  @default
    @include('dashboard.partials.license-issued')
@endswitch
@endsection
