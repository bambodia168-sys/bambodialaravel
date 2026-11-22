<nav aria-label="Legal pages">
  @foreach(['terms' => 'Terms of Service', 'privacy' => 'Privacy Policy', 'cookies' => 'Cookie Policy'] as $category => $label)
    @if($policies->has($category))
      <a href="{{ $policies->get($category)->publicUrl() }}">{{ $label }}</a>
    @endif
  @endforeach
  <a href="{{ route('policy-center') }}">Policy Center</a>
</nav>
