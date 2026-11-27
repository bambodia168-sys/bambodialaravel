@extends('layouts.main')
@section('title', $checkout ? 'Checkout' : 'Cart')
@section('content')
@if($checkout && $methods->isNotEmpty() && ($totals['USD'] ?? 0) > 0)
  <link rel="stylesheet" href="{{ asset('css/checkout-payment-methods.css') }}?v=2">
  <script defer src="{{ asset('js/checkout-payment-methods.js') }}?v=1"></script>
@endif
<div class="container inner-page storefront">
  <x-checkout-progress :step="$checkout ? 'checkout' : 'cart'"/>
  <div class="page-intro">
    <h1>{{ $checkout ? 'Checkout' : 'Your cart' }}</h1>
    @if($lines !== [])
      <p>{{ $checkout ? 'Review your selections before placing an order.' : 'Review your items and license selections.' }}</p>
    @endif
  </div>

  @if($added = session('cart_added'))
    <p class="storefront-panel storefront-panel-head" role="status">Added to cart · {{ is_array($added) ? $added['title'] : $added }}</p>
  @endif

  @if($lines === [])
    <div class="storefront-panel storefront-empty">
      <span class="storefront-icon"><x-icon name="shopping-cart" size="24"/></span>
      <h2>Your cart is empty</h2>
      <a class="btn primary" href="{{ route('marketplace.search') }}">Browse listings</a>
    </div>
  @else
    <div class="storefront-columns">
      <section class="storefront-panel" aria-labelledby="cart-items">
        <div class="storefront-panel-head">
          <h2 id="cart-items">Items</h2>
          @if($checkout)<a href="{{ route('cart') }}">Edit cart</a>@endif
        </div>
        @foreach($lines as $id => $line)
          @php $offer = $line['listing']; $quote = $line['current']; @endphp
          <article class="storefront-item">
            <span class="storefront-icon"><x-icon :name="$offer?->type === 'product' ? 'package' : 'briefcase'" size="19"/></span>
            <div>
              <h3>
                @if($quote)
                  <a href="{{ $offer->publicUrl() }}">{{ $offer->title }}</a>
                @else
                  {{ $line['title'] }}
                @endif
              </h3>
              @if($quote)
                <p>{{ $offer->creator->name }}@if($quote['license']) · {{ $quote['license'] }}@endif</p>
                @if($line['quote'] !== $quote['signature'])<p class="storefront-notice">The price or terms changed. Review the updated offer before continuing.</p>@endif
              @else
                <p class="storefront-notice">This item is no longer available for checkout.</p>
              @endif
            </div>
            <div class="storefront-item-controls">
              @if($quote)<strong>{{ $quote['currency'] }} {{ number_format((float) $quote['total'], 2) }}</strong>@endif
              @if(!$checkout)
                <form method="post" action="{{ route('cart.remove', $id) }}">
                  @csrf @method('DELETE')
                  <button class="storefront-text-button" aria-label="Remove {{ $line['title'] }}">Remove</button>
                </form>
              @elseif(auth()->user()?->hasVerifiedEmail() && $quote)
                @if($quote['total'] === '0.00' || ($quote['currency'] === 'USD' && $methods->isNotEmpty()))
                  <form method="post" action="{{ route('checkout.place', $id) }}" @if($quote['total'] !== '0.00') data-checkout-payment @endif>
                    @csrf
                    <input type="hidden" name="tier" value="{{ $line['tier'] }}">
                    <input type="hidden" name="request_key" value="{{ $line['request_key'] }}">
                    <input type="hidden" name="quote" value="{{ $quote['signature'] }}">
                    <button class="btn primary">{{ $quote['total'] === '0.00' ? 'Place free order' : 'Continue to payment' }}</button>
                  </form>
                @else
                  <small>Payment is currently unavailable for this item.</small>
                @endif
              @endif
            </div>
          </article>
        @endforeach
      </section>

      <aside class="storefront-panel storefront-summary" aria-labelledby="cart-summary">
        <h2 id="cart-summary">Order summary</h2>
        <dl>
          <div><dt>Items</dt><dd>{{ count($lines) }}</dd></div>
          @foreach($totals as $currency => $total)
            <div><dt>{{ $currency }} total</dt><dd>{{ $currency }} {{ number_format($total / 100, 2) }}</dd></div>
          @endforeach
        </dl>
        @if(count($lines) > 1)
          <p>Each listing is placed and paid for separately.</p>
        @endif
        @if($checkout && !$collectingPayments && max([0, ...array_values($totals)]) > 0)
          <p role="status">Paid checkout is currently unavailable. Your cart stays saved.</p>
        @endif
        @if(!$checkout && $totals !== [])
          @if(auth()->check() && !auth()->user()->hasVerifiedEmail())
            <p class="storefront-notice">Verify your account email before placing an order. Your cart will remain saved.</p>
          @endif
          <a class="btn primary" href="{{ route('checkout') }}">{{ auth()->check() && !auth()->user()->hasVerifiedEmail() ? 'Verify email to continue' : 'Review checkout' }}</a>
        @elseif($checkout && !auth()->check() && $totals !== [])
          <a class="btn primary" href="{{ route('checkout', ['account' => 'login']) }}">Sign in to continue</a>
          @if($canRegister)<a class="btn subtle" href="{{ route('checkout', ['account' => 'register']) }}">Create account</a>@endif
        @elseif($checkout && $methods->isNotEmpty() && ($totals['USD'] ?? 0) > 0)
          <fieldset class="checkout-payment-methods" data-checkout-payment-methods>
            <legend>Payment methods</legend>
            @foreach($methods as $method)
              <label class="checkout-payment-option">
                <x-payment-method-icon :provider="$method->provider"/>
                <span>{{ $method->name }}</span>
                <input type="radio" name="payment_method" value="{{ $method->id }}"
                  @checked((string) old('payment_method') === (string) $method->id)>
              </label>
            @endforeach
          </fieldset>
          @error('payment_method')<p class="storefront-notice" role="alert">{{ $message }}</p>@enderror
        @endif
      </aside>
    </div>
  @endif
</div>

@if($mode === 'login' || ($mode === 'register' && $canRegister))
  @php $signingUp = $mode === 'register'; @endphp
  <dialog class="storefront-dialog" aria-labelledby="checkout-auth-title" data-storefront-open>
    <div class="storefront-dialog-head">
      <span class="storefront-icon"><x-icon name="user" size="22"/></span>
      <a href="{{ route('checkout') }}" class="icon-button" aria-label="Close"><x-icon name="x" size="20"/></a>
    </div>
    <h2 id="checkout-auth-title">{{ $signingUp ? 'Create your account' : 'Sign in to checkout' }}</h2>
    <p>Your cart will be saved to your account.</p>
    @if($errors->any())
      <ul class="storefront-errors" role="alert">@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>
    @endif
    @include($signingUp ? 'auth.partials.register-form' : 'auth.partials.login-form')
    @if($canRegister)
      <p class="storefront-auth-switch">
        <a href="{{ route('checkout', ['account' => $signingUp ? 'login' : 'register']) }}">{{ $signingUp ? 'Already have an account? Sign in' : 'New to Bambodia? Create an account' }}</a>
      </p>
    @endif
  </dialog>
  <noscript><div class="container"><a class="btn primary" href="{{ route($signingUp ? 'register' : 'login') }}">{{ $signingUp ? 'Create account' : 'Sign in' }}</a></div></noscript>
@endif
@endsection
