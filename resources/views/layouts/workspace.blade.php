<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  @include('layouts.partials.site-brand-head')
  <title>{{ trim($__env->yieldContent('title','Workspace')) }} · {{ \App\Models\SiteSetting::setting('identity.name') }}</title>
  <meta name="robots" content="noindex,nofollow">
  <script>document.documentElement.dataset.theme = localStorage.getItem('bambodia-theme') || @json(\App\Models\SiteSetting::setting('site.default_theme'));</script>
  @vite(['resources/css/app.css','resources/js/app.js'])
  @include('layouts.partials.site-typography')
  {{-- Badge tips are portalled to a viewport-safe layer without changing their artwork or layout. --}}
  <link rel="stylesheet" href="{{ asset('css/creator-badge-tooltip-viewport.css') }}?v=1">
  <script defer src="{{ asset('js/creator-badge-tooltip-viewport.js') }}?v=1"></script>
  @if(request()->routeIs('creators.show','account.profile.edit','studio.profile.edit','dashboard.users.profile.edit','localized.*.creators.show','localized.*.account.profile.edit','localized.*.studio.profile.edit','localized.*.dashboard.users.profile.edit'))
    <link rel="stylesheet" href="{{ asset('css/creator-profile-v3.css') }}?v=3">
    <script defer src="{{ asset('js/creator-profile-v3.js') }}?v=2"></script>
  @endif
</head>
@php
  $workspace = trim($__env->yieldContent('workspace', 'account'));
  $workspace = in_array($workspace, ['admin','account','creator','developer'], true) ? $workspace : 'account';
  $user = auth()->user();
  $currentName = preg_replace('/^localized\.(?:en|zh|km|ko|ja|th|vi|de|fr|hi|id|ms|es)\./', '', request()->route()?->getName() ?? '');
  $adminWorkspaceLabel = $user->role === 'staff' ? 'Staff Workspace' : 'Administration';
  $workspaceTitles = ['admin'=>$adminWorkspaceLabel,'account'=>'My Account','creator'=>'Creator Studio','developer'=>'Developer Workspace'];
  $workspaceDescription = [
    'admin'=>$user->role === 'staff' ? 'Permitted staff support operations' : 'Platform operations and management',
    'account'=>'Personal account and activity',
    'creator'=>'Creative work and customer delivery',
    'developer'=>'Integrations and API security',
  ];
  $groups = [];

  if ($workspace === 'admin') {
    $groups[] = ['heading'=>'Overview','items'=>[
      ['route'=>'dashboard','label'=>$user->role === 'staff' ? 'Support overview' : 'Dashboard overview','icon'=>'layout-dashboard'],
      ['route'=>'dashboard.badges.index','label'=>'Creator Badges','icon'=>'award'],
      ['route'=>'account.profile.edit','label'=>'My Profile','icon'=>'user-round'],
      ['route'=>'account.security','label'=>'Account Security','icon'=>'shield-check'],
    ]];
    if ($user->role === 'super_admin') {
      $groups[] = ['heading'=>'Access & Security','items'=>[
        ['route'=>'dashboard.users','label'=>'User Manager','icon'=>'users'],
        ['route'=>'dashboard.account-verifications.index','label'=>'Account Verification','icon'=>'user-round-check'],
        ['route'=>'security.index','label'=>'Security Center','icon'=>'shield-check'],
        ['route'=>'dashboard.features','label'=>'Feature Manager','icon'=>'sliders-horizontal'],
        ['route'=>'dashboard.entitlements.index','label'=>'Download Entitlements','icon'=>'key-round'],
        ['route'=>'dashboard.compliance.index','label'=>'Compliance Register','icon'=>'clipboard-check'],
        ['route'=>'dashboard.privacy.index','label'=>'Privacy Requests','icon'=>'user-round-check'],
        ['route'=>'dashboard.turnstile.index','label'=>'Bot Protection','icon'=>'shield-check'],
      ]];
    }
    if ($user->role === 'admin') {
      $groups[] = ['heading'=>'Trust & Safety','items'=>[
        ['route'=>'dashboard.account-verifications.index','label'=>'Account Verification','icon'=>'user-round-check'],
      ]];
    }
    if (in_array($user->role,['super_admin','admin'],true)) {
      $groups[] = ['heading'=>'Appearance & Content','items'=>[
        ['route'=>'dashboard.appearance','label'=>'Appearance','icon'=>'palette'],
        ['route'=>'dashboard.site-identity','label'=>'Site Identity','icon'=>'image'],
        ['route'=>'dashboard.seo-settings','label'=>'SEO Settings','icon'=>'search'],
        ['route'=>'dashboard.social.index','label'=>'Footer Social Links','icon'=>'globe'],
        ['route'=>'dashboard.languages.index','label'=>'Language Manager','icon'=>'globe'],
        ['route'=>'dashboard.typography.index','label'=>'Typography','icon'=>'palette'],
        ['route'=>'dashboard.system-settings','label'=>'System Settings','icon'=>'settings'],
        ['route'=>'dashboard.ai-draft.index','label'=>'AI Draft Studio','icon'=>'sparkles','roles'=>['super_admin']],
        ['route'=>'dashboard.pages','label'=>'Page Manager','icon'=>'file-text'],
        ['route'=>'dashboard.posts','label'=>'Post Manager','icon'=>'newspaper'],
        ['route'=>'dashboard.policies','label'=>'Policy Center','icon'=>'scale'],
        ['route'=>'dashboard.knowledge.index','label'=>'Knowledge Base','icon'=>'book-open'],
      ]];
      $groups[] = ['heading'=>'Marketplace Management','items'=>[
        ['route'=>'dashboard.products.index','label'=>'Digital Products','icon'=>'package'],
        ['route'=>'dashboard.jobs.index','label'=>'Job Manager','icon'=>'briefcase'],
        ['route'=>'dashboard.contracts.index','label'=>'Contracts','icon'=>'file-check'],
        ['route'=>'dashboard.contracts.disputes','label'=>'Contract Disputes','icon'=>'alert'],
        ['route'=>'dashboard.categories.index','label'=>'Categories','icon'=>'layers'],
        ['route'=>'dashboard.marketplace.index','label'=>'Projects','icon'=>'folders','params'=>['type'=>'project']],
        ['route'=>'dashboard.marketplace.index','label'=>'Freelance','icon'=>'user-check','params'=>['type'=>'freelance']],
        ['route'=>'dashboard.marketplace.index','label'=>'Services','icon'=>'wrench','params'=>['type'=>'service']],
        ['route'=>'dashboard.inquiries.index','label'=>'Service Inquiries','icon'=>'message'],
      ]];
      $groups[] = ['heading'=>'Assets & Communication','items'=>[
        ['route'=>'dashboard.media.index','label'=>'Media Library','icon'=>'image'],
        ['route'=>'dashboard.cloud-storage.index','label'=>'Cloud Storage','icon'=>'archive','roles'=>['super_admin']],
        ['route'=>'dashboard.vault.index','label'=>'Digital File Vault','icon'=>'archive'],
        ['route'=>'dashboard.licenses.index','label'=>'License Manager','icon'=>'scroll-text'],
        ['route'=>'dashboard.creator-applications','label'=>'Creator Applications','icon'=>'user-round-check'],
        ['route'=>'dashboard.reviews','label'=>'Review Queue','icon'=>'clipboard-check'],
        ['route'=>'dashboard.preview-visibility.index','label'=>'Preview Visibility','icon'=>'shield-check','roles'=>['super_admin']],
        ['route'=>'dashboard.notifications.index','label'=>'Notification Analytics','icon'=>'bell'],
      ]];
    }
    $groups[] = ['heading'=>'Assistance','items'=>[
      ['route'=>'dashboard.support','label'=>'Support Center','icon'=>'life-buoy'],
    ]];
  } elseif ($workspace === 'creator') {
    $groups = [
      ['heading'=>'Creator Overview','items'=>[
        ['route'=>'creator','label'=>'Studio Overview','icon'=>'layout-dashboard'],
        ['route'=>'studio.profile.edit','label'=>'Public Creator Profile','icon'=>'user-round-check'],
        ['route'=>'studio.listings','label'=>'Listings & Services','icon'=>'folders'],
      ]],
      ['heading'=>'Products & Resources','items'=>[
        ['route'=>'studio.products.index','label'=>'Product Releases','icon'=>'package'],
        ['route'=>'studio.licenses.index','label'=>'Product Licenses','icon'=>'key-round'],
        ['route'=>'studio.media.index','label'=>'Media Library','icon'=>'image'],
      ]],
      ['heading'=>'Client Work','items'=>[
        ['route'=>'studio.inquiries','label'=>'Client Inquiries','icon'=>'message'],
        ['route'=>'contracts.index','label'=>'Contracts','icon'=>'file-check'],
        ['route'=>'resume.edit','label'=>'Resume Builder','icon'=>'file-text'],
      ]],
      ['heading'=>'Saved & Shared','items'=>[
        ['route'=>'jobs.applied','label'=>'My Proposals','icon'=>'send','roles'=>['freelancer']],
        ['route'=>'account.saved-listings','label'=>'Saved Listings','icon'=>'star'],
        ['route'=>'account.saved-jobs','label'=>'Saved Jobs','icon'=>'bookmark'],
      ]],
    ];
  } elseif ($workspace === 'developer') {
    $groups = [
      ['heading'=>'Developer Overview','items'=>[
        ['route'=>'developer.index','label'=>'API Credentials','icon'=>'key-round'],
        ['route'=>'developer.docs','label'=>'API Documentation','icon'=>'book-open'],
      ]],
      ['heading'=>'Operational Tools','items'=>[
        ['route'=>'security.index','label'=>'Security Center','icon'=>'shield-check'],
        ['route'=>'dashboard.features','label'=>'Feature Manager','icon'=>'sliders-horizontal'],
        ['route'=>'dashboard','label'=>'Admin Overview','icon'=>'layout-dashboard'],
      ]],
    ];
  } else {
    $groups = [
      ['heading'=>'Your Account','items'=>[
        ['route'=>'account','label'=>'Account Overview','icon'=>'layout-dashboard'],
        ['route'=>'account.profile.edit','label'=>'Edit Profile','icon'=>'user-round'],
        ['route'=>'account.notifications','label'=>'Notifications','icon'=>'bell'],
        ['route'=>'account.downloads','label'=>'My Downloads','icon'=>'download'],
        ['route'=>'account.media.index','label'=>'My Media Library','icon'=>'image'],
        ['route'=>'account.licenses.index','label'=>'My Licenses','icon'=>'key-round'],
        ['route'=>'account.saved-listings','label'=>'Saved Listings','icon'=>'star'],
        ['route'=>'account.saved-jobs','label'=>'Saved Jobs','icon'=>'bookmark'],
      ]],
      ['heading'=>'Orders & Communication','items'=>[
        ['route'=>'contracts.index','label'=>'My Contracts','icon'=>'file-check'],
        ['route'=>'jobs.mine','label'=>'My Job Posts','icon'=>'briefcase','roles'=>['customer','subscriber','super_admin']],
        ['route'=>'account.inquiries','label'=>'Service Requests','icon'=>'message','roles'=>['customer','subscriber']],
        ['route'=>'jobs.applied','label'=>'My Proposals','icon'=>'send','roles'=>['freelancer']],
      ]],
      ['heading'=>'Privacy & Records','items'=>[
        ['route'=>'account.policy-receipts','label'=>'Policy Receipts','icon'=>'scroll-text'],
        ['route'=>'privacy.index','label'=>'Privacy Requests','icon'=>'shield-check'],
        ['route'=>'support','label'=>'Support Center','icon'=>'life-buoy'],
        ['route'=>'support.tickets','label'=>'My Tickets','icon'=>'message'],
      ]],
    ];
    if ($user->isCreator()) {
      $groups[]=['heading'=>'Creator Access','items'=>[
        ['route'=>'creator','label'=>'Creator Studio','icon'=>'palette'],
        ['route'=>'studio.profile.edit','label'=>'Creator Profile','icon'=>'user-round-check'],
      ]];
    } elseif (in_array($user->role,['customer','subscriber'],true)) {
      $groups[]=['heading'=>'Creator Access','items'=>[
        ['route'=>'creator.apply','label'=>'Become a Creator','icon'=>'user-round-check'],
      ]];
    }
  }

  $workspaceRoot = match ($workspace) {
    'admin' => route('dashboard'),
    'creator' => route('creator'),
    'developer' => route('developer.index'),
    default => route('account'),
  };
@endphp
<body class="dashboard-body workspace-body" data-workspace="{{ $workspace }}">
<x-flash-feedback :validation-errors="$errors->all()" />
<div class="workspace-shell" id="workspace-shell">
  <aside class="workspace-sidebar" id="workspace-sidebar" aria-label="{{ $workspaceTitles[$workspace] }} navigation">
    <div class="workspace-sidebar-head">
      <a class="workspace-brand" href="{{ $workspaceRoot }}" title="{{ $workspaceTitles[$workspace] }}">
        <span class="workspace-brand-name brand-logo-pair workspace-brand-pair">
          <img class="brand-logo-light" src="{{ \App\Models\SiteBrandAsset::urlFor('logo_light') }}" alt="{{ \App\Models\SiteSetting::setting('identity.name') }}" width="550" height="144" decoding="async">
          <img class="brand-logo-dark" src="{{ \App\Models\SiteBrandAsset::urlFor('logo_dark') }}" alt="{{ \App\Models\SiteSetting::setting('identity.name') }}" width="550" height="144" decoding="async">
        </span>
        <span class="brand-mark workspace-brand-mini" aria-hidden="true">B</span>
      </a>
      <span class="workspace-kind"><x-icon name="circle-dot" size="14"/><span>{{ $workspaceTitles[$workspace] }}</span></span>
    </div>
    <nav class="workspace-navigation" aria-label="{{ $workspaceTitles[$workspace] }} menu" id="workspace-navigation">
      @foreach($groups as $group)
        <div class="workspace-nav-group">
          <div class="workspace-nav-heading">{{ $group['heading'] }}</div>
          @foreach($group['items'] as $item)
            @continue(isset($item['roles']) && !in_array($user->role,$item['roles'],true))
            @php
              $match = ($currentName === $item['route']
                || ($item['route'] === 'dashboard.users' && str_starts_with($currentName, 'dashboard.users.'))
                || ($item['route'] === 'dashboard.knowledge.index' && str_starts_with($currentName, 'dashboard.knowledge.')))
                && (!isset($item['params']) || (request()->route('type') === ($item['params']['type'] ?? null)));
            @endphp
            <a href="{{ route($item['route'], $item['params'] ?? []) }}"
               class="workspace-nav-link {{ $match ? 'active' : '' }}"
               title="{{ $item['label'] }}"
               @if($match) aria-current="page" @endif>
              <x-icon :name="$item['icon']" size="19"/>
              <span class="workspace-nav-text">{{ $item['label'] }}</span>
            </a>
          @endforeach
        </div>
      @endforeach
      <div class="workspace-empty-search" hidden>No matching menu items</div>
    </nav>
    <div class="workspace-sidebar-bottom" aria-label="Switch workspaces">
      @if($workspace !== 'admin' && $user->isAdministrator())
      <a href="{{ route('dashboard') }}" class="workspace-nav-link" title="{{ $adminWorkspaceLabel }}">
        <x-icon name="layout-dashboard" size="19"/><span class="workspace-nav-text">{{ $adminWorkspaceLabel }}</span>
      </a>
      @endif
      @if($workspace !== 'developer' && $user->role === 'super_admin')
      <a href="{{ route('developer.index') }}" class="workspace-nav-link" title="Developer Workspace">
        <x-icon name="terminal" size="19"/><span class="workspace-nav-text">Developer Workspace</span>
      </a>
      @endif
      @if($workspace !== 'creator' && $user->isCreator())
      <a href="{{ route('creator') }}" class="workspace-nav-link" title="Creator Studio">
        <x-icon name="palette" size="19"/><span class="workspace-nav-text">Creator Studio</span>
      </a>
      @endif
      @if($workspace !== 'account')
      <a href="{{ route('account') }}" class="workspace-nav-link" title="My Account">
        <x-icon name="user" size="19"/><span class="workspace-nav-text">My Account</span>
      </a>
      @endif
      <a href="{{ route('home') }}" class="workspace-nav-link" title="Public website">
        <x-icon name="home" size="19"/><span class="workspace-nav-text">Public website</span>
      </a>
    </div>
  </aside>
  <div class="workspace-backdrop" data-workspace-backdrop aria-hidden="true"></div>
  <div class="workspace-main" id="workspace-main">
    <header class="workspace-topbar">
      <button type="button" class="icon-button workspace-sidebar-toggle"
              data-workspace-toggle aria-label="Collapse navigation"
              aria-controls="workspace-sidebar" aria-expanded="true"
              title="Show or hide sidebar">
        <span class="workspace-collapse-icon"><x-icon name="panel-left-close" size="20"/></span>
        <span class="workspace-expand-icon"><x-icon name="panel-left-open" size="20"/></span>
      </button>
      <div class="workspace-top-title">
        <strong>{{ $workspaceTitles[$workspace] }}</strong>
      </div>
      <div class="workspace-menu-search">
        <x-icon name="search" size="19"/>
        <input type="search" data-workspace-search
               placeholder="Search {{ strtolower($workspaceTitles[$workspace]) }} menu"
               aria-label="Search {{ $workspaceTitles[$workspace] }} navigation"
               autocomplete="off">
        <span class="workspace-search-hint">Menu</span>
      </div>
      <div class="workspace-top-actions">
        <a class="icon-button workspace-home-link" href="{{ route('home') }}" title="Home" aria-label="Home"><x-icon name="home" size="19"/></a>
        <button type="button" class="icon-button" data-theme-toggle aria-label="Toggle light or dark mode"><x-icon name="moon" size="19"/></button>
        <x-language-menu/>
        <details class="workspace-account-menu">
          <summary class="workspace-profile" aria-label="Account menu" title="{{ $user->name }}">
            <x-icon name="user" size="19"/>
          </summary>
          <div class="workspace-account-panel">
            <div class="workspace-account-identity">
              <strong>{{ $user->name }}</strong>
              <small>{{ ucwords(str_replace('_',' ',$user->role)) }}</small>
            </div>
            <a href="{{ route('account') }}"><x-icon name="user" size="17"/> My Account</a>
            @if($user->isCreator())
              <a href="{{ route('creator') }}"><x-icon name="palette" size="17"/> Creator Studio</a>
            @endif
            @if($user->isAdministrator())
              <a href="{{ route('dashboard') }}"><x-icon name="layout-dashboard" size="17"/> {{ $adminWorkspaceLabel }}</a>
            @endif
            @if($user->role === 'super_admin')
              <a href="{{ route('developer.index') }}"><x-icon name="terminal" size="17"/> Developer Workspace</a>
            @endif
            <form method="post" action="{{ route('logout') }}">
              @csrf
              <button type="submit"><x-icon name="logout" size="17"/> Sign out</button>
            </form>
          </div>
        </details>
      </div>
    </header>
    <main class="workspace-content" id="workspace-content">
      @yield('content')
    </main>
  </div>
</div>
</body>
</html>
