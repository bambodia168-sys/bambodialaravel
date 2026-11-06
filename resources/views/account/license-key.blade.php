@extends('layouts.workspace')
@section('workspace','account')
@section('title','Reveal Product License')
@section('content')
<div class="container inner-page">
  <div class="page-intro"><div class="eyebrow">Secure license access</div><h1>Installer license key</h1>
    <p>Key for {{ $license->product?->title }}. Do not publish or share this secret. It is not your account password.</p></div>
  <section class="panel">
    <div class="panel-head"><h2>{{ ucfirst($license->tier) }} License</h2></div>
    <p class="hint">Paste this key into the installed script's purchase/license field, then activate it for your domain. Online verification must use HTTPS.</p>
    <div class="license-secret-row">
      <code id="license-key-content">{{ $secret }}</code>
      <button type="button" class="btn primary" data-copy-target="license-key-content"><x-icon name="copy" size="16"/> Copy key</button>
    </div>
    <p class="hint">This response is private and marked no-store. Keep it confidential; the administrator can rotate a compromised key.</p>
    <a class="btn subtle" href="{{ route('account.licenses.index') }}">Back to My Licenses</a>
  </section>
</div>
@endsection
