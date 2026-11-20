@props(['listing'])
<form method="post" id="listing-cart-add" data-cart-add="{{ $listing->public_id }}" action="{{ route('cart.add', $listing->public_id) }}">
  @csrf
  @if($listing->type !== 'product')
    <input type="hidden" name="tier" value="standard">
  @endif
  <button type="submit" class="btn primary premium-full-action" data-cart-action>
    <span data-cart-add-icon><x-icon name="shopping-cart" size="18"/></span>
    <span data-cart-added-icon hidden><x-icon name="check" size="18"/></span>
    <span data-cart-action-label>Add to cart</span>
  </button>
</form>
<p class="mini-cart-feedback" role="alert" data-cart-error hidden></p>
