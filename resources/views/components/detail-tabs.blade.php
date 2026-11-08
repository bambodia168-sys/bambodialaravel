@props(['kind', 'reference', 'slug', 'publicUrl', 'sections' => [], 'active' => 'overview'])

@php
  $params = compact('kind', 'reference', 'slug');
  $sections = $sections ?: [
    ['id'=>'overview', 'label'=>'Overview'],
    ['id'=>$kind === 'product' ? 'license' : ($kind === 'job' ? 'requirements' : 'workflow'),
     'label'=>$kind === 'product' ? 'License' : ($kind === 'job' ? 'How proposals work' : 'How it works')],
    ['id'=>$kind === 'job' ? 'client' : 'seller', 'label'=>$kind === 'job' ? 'Client' : 'Creator'],
  ];
@endphp
<nav class="premium-detail-tabs premium-detail-tabs--shared" aria-label="{{ ucfirst($kind) }} sections" data-detail-tabs>
  @foreach($sections as $section)
    @php
      $isOverview = $active === 'overview' && $section['id'] === 'overview';
      $href = $active === 'overview'
        ? '#'.$section['id']
        : $publicUrl.($section['id'] === 'overview' ? '' : '#'.$section['id']);
    @endphp
    <a href="{{ $href }}" @if($isOverview) aria-current="page" @endif
       @if($active !== 'overview') data-detail-route @endif>{{ $section['label'] }}</a>
  @endforeach
  @auth
    <a href="{{ route('detail.comments',$params) }}" data-detail-route
       @if($active === 'comments') aria-current="page" @endif>Comment</a>
    <a href="{{ route('detail.support',$params) }}" data-detail-route
       @if($active === 'support') aria-current="page" @endif>Support</a>
  @endauth
</nav>
