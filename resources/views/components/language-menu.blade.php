@props(['compact' => false])
@php
$names = ['en'=>'English','zh'=>'中文','km'=>'ខ្មែរ','ko'=>'한국어','ja'=>'日本語','th'=>'ไทย','vi'=>'Tiếng Việt','de'=>'Deutsch'];
$current = app()->getLocale();
@endphp
<details {{ $attributes->class(['language-menu', 'auth-language-menu' => $compact]) }}>
  <summary class="language-trigger" aria-label="{{ __('ui.language') }}: {{ $names[$current] ?? 'English' }}">
    <x-language-flag :code="$current"/>
    <span class="language-current">{{ $names[$current] ?? 'English' }}</span>
    <x-icon name="chevron-down" size="15"/>
  </summary>
  <div class="language-options" role="group" aria-label="{{ __('ui.language') }}">
    @foreach($names as $code => $native)
      <form method="post" action="{{ route('locale.update') }}">
        @csrf
        <button type="submit" name="locale" value="{{ $code }}" class="language-option {{ $current === $code ? 'selected' : '' }}" @if($current === $code) aria-current="true" @endif>
          <x-language-flag :code="$code"/>
          <span>{{ $native }}</span>
          @if($current === $code)<x-icon name="check" size="15" />@endif
        </button>
      </form>
    @endforeach
  </div>
</details>
