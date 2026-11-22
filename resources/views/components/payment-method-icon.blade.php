@props(['provider'])

<span class="payment-method-icon" data-payment-icon="{{ $provider }}">
  @if(in_array($provider, ['khqr', 'bakong'], true))
    <img src="{{ asset('images/payments/khqr-red.svg') }}" alt="" width="34" height="9">
  @else
  <svg width="29" height="29" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
    @switch($provider)
      @case('bank_transfer')
        <path d="m3 8 9-5 9 5H3Zm2 3v7m5-7v7m4-7v7m5-7v7M3 21h18"/>
        @break
      @case('aba')
        <path d="M3 3h6v6H3zM15 3h6v6h-6zM3 15h6v6H3zM15 13v3h3v-3h3v4m-8 2v2h4v-2m4 2h.01"/>
        @break
      @case('stripe')
        <rect x="2" y="5" width="20" height="14" rx="2"/>
        <path d="M2 10h20M6 15h4"/>
        @break
      @case('wing')
        <rect x="6" y="2" width="12" height="20" rx="2"/>
        <path d="m9 11 2 2 4-4m-4 9h2"/>
        @break
      @default
        <path d="M20 8V6a2 2 0 0 0-2-2H5a2 2 0 1 0 0 4h14a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6m18 7h-5a2 2 0 0 0 0 4h5m-5-2h.01"/>
    @endswitch
  </svg>
  @endif
</span>
