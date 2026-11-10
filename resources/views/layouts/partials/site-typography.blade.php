@php $typographyRules=\App\Models\SiteTypographyRule::forLocale(app()->getLocale()); @endphp
@if($typographyRules->isNotEmpty())
<style id="site-language-typography" data-locale="{{ app()->getLocale() }}">
@foreach($typographyRules as $rule)
@php
  $family=\App\Models\SiteTypographyRule::FONT_STACKS[$rule->font_family]??\App\Models\SiteTypographyRule::FONT_STACKS['system'];
  $selector=\App\Models\SiteTypographyRule::SELECTORS[$rule->element]??'body';
@endphp
{{ $selector }}{
font-family: {{ $family }} !important;
font-size: {{ (int)$rule->font_size }}px !important;
font-weight: {{ (int)$rule->font_weight }} !important;
line-height: {{ number_format((float)$rule->line_height,2,'.','') }} !important;
text-align: {{ $rule->alignment }} !important;
}
@endforeach
</style>
@endif
