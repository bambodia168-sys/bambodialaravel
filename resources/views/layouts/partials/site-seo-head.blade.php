@php
  $siteName=\App\Models\SiteSetting::setting('identity.name');
  $seoPage=\App\Models\SiteSeoPage::forRequest(request());
  $requestedTitle=trim($__env->yieldContent('title',''));
  $pageTitle=$seoPage?->title
    ?: ($requestedTitle!=='' && $requestedTitle!==$siteName ? $requestedTitle.' · '.$siteName : \App\Models\SiteSetting::setting('seo.default_title'));
  $requestedDescription=trim($__env->yieldContent('meta_description',''));
  $pageDescription=$seoPage?->description
    ?: ($requestedDescription!=='' ? $requestedDescription : \App\Models\SiteSetting::setting('seo.default_description'));
  $privatePath=request()->is('login','register','forgot-password','reset-password*',
    'dashboard*','account*','studio*','developer*','checkout*','cart*');
  $robots=$privatePath ? 'noindex,nofollow'
    : ($seoPage ? ($seoPage->is_indexable?'index,follow':'noindex,nofollow')
        : \App\Models\SiteSetting::setting('seo.default_robots'));
  $canonical=$seoPage?->canonical_url ?: url()->current();
  $keywords=$seoPage?->keywords ?: \App\Models\SiteSetting::setting('seo.default_keywords');
  $ogImage=$seoPage?->og_image_url ?: \App\Models\SiteSetting::setting('seo.default_og_image');
@endphp
<title>{{ $pageTitle }}</title>
<meta name="description" content="{{ $pageDescription }}">
<meta name="robots" content="{{ $robots }}">
@if($keywords!=='')<meta name="keywords" content="{{ $keywords }}">@endif
<link rel="canonical" href="{{ $canonical }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:title" content="{{ $pageTitle }}">
<meta property="og:description" content="{{ $pageDescription }}">
<meta property="og:url" content="{{ $canonical }}">
@if($ogImage!=='')<meta property="og:image" content="{{ $ogImage }}">@endif
<meta name="twitter:card" content="{{ $ogImage!==''?'summary_large_image':'summary' }}">
<meta name="twitter:title" content="{{ $pageTitle }}">
<meta name="twitter:description" content="{{ $pageDescription }}">
@if($ogImage!=='')<meta name="twitter:image" content="{{ $ogImage }}">@endif
