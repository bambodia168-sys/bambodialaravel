@props(['action'])
@php $turnstile=\App\Models\TurnstileBotProtection::forAction($action); @endphp
@if($turnstile)
  <div class="auth-turnstile-check" role="group" aria-label="Security verification">
    <div class="cf-turnstile" data-sitekey="{{ $turnstile->site_key }}"
      data-action="{{ $action }}" data-theme="auto" data-size="flexible"></div>
    <small class="hint">Complete the security check before continuing.</small>
  </div>
  @once
    <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
  @endonce
@endif
