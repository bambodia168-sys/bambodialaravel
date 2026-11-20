<div class="mini-cart-float" data-cart-float hidden>
  <a class="mini-cart-float-link" href="{{ route('cart') }}">
    <span class="mini-cart-float-icon"><x-icon name="shopping-cart" size="19"/></span>
    <span>
      <strong data-cart-float-label>Added to cart</strong>
      <small><span data-cart-float-count></span> · View cart</small>
    </span>
  </a>
  <button type="button" class="mini-cart-dismiss" data-cart-dismiss aria-label="Dismiss cart confirmation">
    <x-icon name="x" size="17"/>
  </button>
</div>
<p class="sr-only" role="status" data-cart-announcement></p>
