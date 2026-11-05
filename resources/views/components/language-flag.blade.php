@props(['code' => 'en'])
@php
$labels = ['en'=>'United Kingdom','zh'=>'China','km'=>'Cambodia','ko'=>'South Korea','ja'=>'Japan','th'=>'Thailand','vi'=>'Vietnam','de'=>'Germany'];
@endphp
<svg {{ $attributes->class(['locale-flag']) }} viewBox="0 0 24 16" width="24" height="16" role="img" aria-label="{{ $labels[$code] ?? $code }} flag" xmlns="http://www.w3.org/2000/svg">
  @switch($code)
    @case('en')
      <rect width="24" height="16" fill="#123B82"/>
      <path d="M0 0 24 16M24 0 0 16" stroke="#fff" stroke-width="4"/>
      <path d="M0 0 24 16M24 0 0 16" stroke="#C8102E" stroke-width="1.7"/>
      <path d="M12 0v16M0 8h24" stroke="#fff" stroke-width="5"/>
      <path d="M12 0v16M0 8h24" stroke="#C8102E" stroke-width="2.6"/>
      @break
    @case('zh')
      <rect width="24" height="16" fill="#DE2910"/>
      <polygon points="5,2 5.8,4.7 8.6,4.7 6.3,6.4 7.2,9 5,7.4 2.8,9 3.7,6.4 1.4,4.7 4.2,4.7" fill="#FFDE00"/>
      <circle cx="10.8" cy="2.5" r="1" fill="#FFDE00"/><circle cx="12" cy="5.1" r=".85" fill="#FFDE00"/><circle cx="11.8" cy="8" r=".85" fill="#FFDE00"/><circle cx="9.8" cy="10.4" r=".85" fill="#FFDE00"/>
      @break
    @case('km')
      <rect width="24" height="16" fill="#032EA1"/><rect y="4" width="24" height="8" fill="#E00025"/>
      <path d="M6 11h12M7 9.5h10M8 8h8M9.4 6.3h5.2M10 4.8h4M11 3h2v1.8M8.7 7.8 10 6.3M15.3 7.8 14 6.3M8 9.5V8M16 9.5V8M7 11V9.5M17 11V9.5" fill="none" stroke="#fff" stroke-width="1"/>
      @break
    @case('ko')
      <rect width="24" height="16" fill="#fff"/>
      <circle cx="12" cy="8" r="4" fill="#CD2E3A"/><path d="M8 8a4 4 0 0 0 8 0 2 2 0 0 0-4 0 2 2 0 0 1-4 0" fill="#0047A0"/>
      <g stroke="#111" stroke-width=".85"><path d="m4 2 3 1.7m-3 0 3 1.7m-3 0 3 1.7M17 10l3 1.7m-3 0 3 1.7M17 13.4l3 1.7"/></g>
      @break
    @case('ja')
      <rect width="24" height="16" fill="#fff"/><circle cx="12" cy="8" r="4.6" fill="#BC002D"/>
      @break
    @case('th')
      <rect width="24" height="16" fill="#A51931"/><rect y="2.7" width="24" height="10.6" fill="#fff"/><rect y="5.3" width="24" height="5.4" fill="#2D2A4A"/>
      @break
    @case('vi')
      <rect width="24" height="16" fill="#DA251D"/>
      <polygon points="12,2 13.65,6.4 18.3,6.4 14.5,9.1 16.05,13.5 12,10.8 7.95,13.5 9.5,9.1 5.7,6.4 10.35,6.4" fill="#FFFF00"/>
      @break
    @case('de')
      <rect width="24" height="16" fill="#000"/><rect y="5.333" width="24" height="5.334" fill="#DD0000"/><rect y="10.667" width="24" height="5.333" fill="#FFCE00"/>
      @break
  @endswitch
</svg>
