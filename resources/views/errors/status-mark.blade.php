@php
  $digits = [
    '0' => 'M85 65a29 48 0 1 1-58 0 29 48 0 1 1 58 0Z',
    '1' => 'M36 35 55 17v96',
    '2' => 'M26 39c1-13 13-22 30-22 17 0 30 10 30 24 0 15-12 23-25 34-16 13-32 24-35 38h62',
    '3' => 'M26 33c7-10 18-16 31-16 17 0 28 10 28 23S73 64 57 64h-7m7 0c18 0 31 10 31 24s-13 25-32 25c-15 0-27-6-34-16',
    '4' => 'M72 17 15 86h83M72 17v96',
    '5' => 'M85 17H35l-8 45c8-8 17-12 29-12 18 0 32 13 32 31s-14 32-33 32c-13 0-24-5-32-15',
    '6' => 'M81 27c-6-7-14-10-24-10-22 0-34 23-34 55 0 25 11 41 33 41 18 0 31-13 31-31 0-17-13-29-29-29-16 0-29 10-33 25',
    '7' => 'M24 17h65l-45 96',
    '8' => 'M56 65c-15 0-28-10-28-24s12-24 28-24 28 10 28 24-13 24-28 24Zm0 0c-19 0-33 10-33 24s14 24 33 24 33-10 33-24-14-24-33-24Z',
    '9' => 'M31 103c6 7 14 10 24 10 22 0 34-23 34-55 0-25-11-41-33-41-18 0-31 13-31 31 0 17 13 29 29 29 16 0 29-10 33-25',
  ];
@endphp
<svg class="site-not-found-mark" width="332" height="130" viewBox="0 0 332 130" role="img" aria-label="{{ $code }}"
     fill="none" stroke="currentColor" stroke-width="12" stroke-linecap="round" stroke-linejoin="round">
  @if($code === 404)
    <path d="M72 17 15 86h83M72 17v96"/>
    <ellipse cx="166" cy="65" rx="37" ry="48" class="site-not-found-zero"/>
    <path d="M291 17 234 86h83M291 17v96"/>
  @else
    @foreach(str_split((string) $code) as $index => $digit)
      <path d="{{ $digits[$digit] }}" transform="translate({{ $index * 110 }} 0)" @class(['site-not-found-zero' => $index === 1])/>
    @endforeach
  @endif
</svg>
