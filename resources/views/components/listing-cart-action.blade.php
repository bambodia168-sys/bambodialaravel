@props(['listing'])
<form method="post" id="listing-cart-add" action="{{ route('cart.add', $listing->public_id) }}">
  @csrf
  @if($listing->type !== 'product')
    <input type="hidden" name="tier" value="standard">
  @endif
  <button class="btn primary premium-full-action"><x-icon name="shopping-cart" size="18"/> Add to cart</button>
</form>
