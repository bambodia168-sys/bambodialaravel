@extends('layouts.workspace')
@section('workspace', $workspace)
@section('title', 'Business Tools')

@push('workspace-styles')
  <link rel="stylesheet" href="{{ asset('css/business-tools.css') }}?v=1">
@endpush

@section('content')
<div class="container inner-page business-tools">
  <header class="business-tools-header">
    <div>
      <h1>{{ __('Business Tools') }}</h1>
      <p>{{ $workspace === 'creator' ? __('Manage your listings, client work, and sales.') : __('Manage marketplace operations, customer support, and publishing.') }}</p>
    </div>
    <div class="business-tools-actions" aria-label="{{ __('Business tools actions') }}">
      @if($tools)
        <form method="get" action="{{ route($route.'.open') }}">
          <label class="sr-only" for="business-tool">{{ __('Business tool') }}</label>
          <select id="business-tool" name="tool" required>
            <option value="">{{ __('Choose a tool') }}</option>
            @foreach($tools as $group => $entries)
              <optgroup label="{{ __($group) }}">
                @foreach($entries as $key => [$label])
                  <option value="{{ $key }}">{{ __($label) }}</option>
                @endforeach
              </optgroup>
            @endforeach
          </select>
          <button class="btn primary compact">{{ __('Open tool') }}</button>
        </form>
      @endif
      @if($canViewOrders)
        <form method="get" action="{{ route($route.'.index') }}">
          <label class="sr-only" for="business-period">{{ __('Order report period') }}</label>
          <select name="days" id="business-period">
            @foreach($periods as $period)
              <option value="{{ $period }}" @selected($period === $days)>{{ __('Last :days days', ['days' => $period]) }}</option>
            @endforeach
          </select>
          <button class="btn subtle compact">{{ __('Apply') }}</button>
        </form>
        <a class="btn subtle compact" href="{{ route($route.'.export', ['days' => $days]) }}">{{ __('Export orders CSV') }}</a>
      @endif
    </div>
  </header>

  @if($errors->has('days') || $errors->has('tool'))
    <p class="business-tools-error" role="alert">{{ $errors->first('days') ?: $errors->first('tool') }}</p>
  @endif

  @if($canViewOrders)
    <section class="business-tools-report" aria-labelledby="business-orders">
      <h2 id="business-orders">{{ __('Order report') }}</h2>
      <p>{{ __('Orders placed in the last :days calendar days, including today.', ['days' => $days]) }}</p>
      <dl class="business-tools-metrics">
        @foreach(\App\Models\MarketplaceOrder::STATUSES as $status => $label)
          <div>
            <dt>{{ __($label) }}</dt>
            <dd>{{ number_format($counts->get($status, 0)) }}</dd>
          </div>
        @endforeach
      </dl>
      @if($totals->isNotEmpty())
        <dl class="business-tools-totals">
          @foreach($totals as $currency => $amount)
            <div>
              <dt>{{ __('Completed order total') }} <span>{{ $currency }}</span></dt>
              <dd>{{ number_format((float) $amount, 2) }}</dd>
            </div>
          @endforeach
        </dl>
      @endif
    </section>
  @endif

  @if($readiness !== null)
    <section class="business-tools-report" aria-labelledby="business-readiness">
      <h2 id="business-readiness">{{ __('Launch checks') }}</h2>
      <dl class="business-tools-metrics">
        <div><dt>{{ __('Public listings with preview labels') }}</dt><dd>{{ number_format($readiness['listings']) }}</dd></div>
        <div><dt>{{ __('Public jobs with preview labels') }}</dt><dd>{{ number_format($readiness['jobs']) }}</dd></div>
        <div><dt>{{ __('Published help articles') }}</dt><dd>{{ number_format($readiness['articles']) }}</dd></div>
        <div><dt>{{ __('Payment submission setting') }}</dt><dd>{{ $readiness['paymentSubmission'] ? __('On') : __('Off') }}</dd></div>
        <div><dt>{{ __('Enabled payment method records') }}</dt><dd>{{ number_format($readiness['enabledMethods']) }}</dd></div>
        <div><dt>{{ __('Configured mail driver') }}</dt><dd>{{ $readiness['mailer'] }}</dd></div>
      </dl>
    </section>
  @endif

  <section aria-labelledby="business-directory">
    <h2 id="business-directory">{{ __('Tool directory') }}</h2>
    @if($tools)
      <div class="business-tools-directory">
        @foreach($tools as $group => $entries)
          <section class="business-tools-group" aria-label="{{ __($group) }}">
            <h3>{{ __($group) }}</h3>
            <dl>
              @foreach($entries as [$label, $description])
                <div>
                  <dt>{{ __($label) }}</dt>
                  <dd>{{ __($description) }}</dd>
                </div>
              @endforeach
            </dl>
          </section>
        @endforeach
      </div>
    @else
      <p>{{ __('Your account currently has no business tool permissions.') }}</p>
    @endif
  </section>
</div>
@endsection
