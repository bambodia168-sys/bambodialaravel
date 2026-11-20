<details class="mini-cart" data-mini-cart data-cart-url="{{ route('cart') }}">
  <summary class="icon-button mini-cart-trigger" aria-label="Cart" title="Cart">
    <x-icon name="shopping-cart" size="19"/>
    <span class="mini-cart-badge" data-cart-badge aria-hidden="true" hidden></span>
  </summary>
  <section class="mini-cart-panel" aria-labelledby="mini-cart-title">
    <div class="mini-cart-heading">
      <h2 id="mini-cart-title">Your cart</h2>
      <span data-cart-count hidden></span>
    </div>
    <p class="mini-cart-note" data-cart-note>Open your cart to see your items.</p>
    <ul class="mini-cart-items" data-cart-items hidden></ul>
    <a class="btn primary mini-cart-view" href="{{ route('cart') }}">View cart</a>
  </section>
</details>
