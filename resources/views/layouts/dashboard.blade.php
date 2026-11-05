<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="csrf-token" content="{{ csrf_token() }}">
  <title>@yield('title','Dashboard') · Bambodia</title>
  <script>document.documentElement.dataset.theme = localStorage.getItem('bambodia-theme') || @json(\App\Models\SiteSetting::setting('site.default_theme'));</script>
  @vite(['resources/css/app.css','resources/js/app.js'])
</head>
<body class="dashboard-body">
<div class="dashboard-shell">
  <aside class="admin-sidebar" id="dashboard-menu">
    <a class="brand admin-brand" href="{{ route('dashboard') }}"><span class="brand-mark">B</span><span>Bambodia<span class="brand-dot">.</span></span></a>
    <div class="sidebar-heading">Workspace</div>
    <nav class="side-nav" aria-label="Administration">
      <a class="{{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><x-icon name="grid"/>Overview</a>
      @if(auth()->user()->role === 'super_admin')
        <div class="sidebar-heading">System Controls</div>
        <a class="{{ request()->routeIs('developer.*') ? 'active' : '' }}" href="{{ route('developer.index') }}"><x-icon name="terminal"/>Developer Dashboard</a>
        <a class="{{ request()->routeIs('security.*') ? 'active' : '' }}" href="{{ route('security.index') }}"><x-icon name="activity"/>Security Dashboard</a>
        <a class="{{ request()->routeIs('dashboard.features*') ? 'active' : '' }}" href="{{ route('dashboard.features') }}"><x-icon name="settings"/>Feature Manager</a>
        <a class="{{ request()->routeIs('dashboard.entitlements.*') ? 'active' : '' }}" href="{{ route('dashboard.entitlements.index') }}"><x-icon name="download"/>Download Entitlements</a>
        <div class="sidebar-heading">Access & People</div>
        <a class="{{ request()->routeIs('dashboard.users*') ? 'active' : '' }}" href="{{ route('dashboard.users') }}"><x-icon name="users"/>User Manager</a>
        <a class="{{ request()->routeIs('dashboard.compliance.*') ? 'active' : '' }}" href="{{ route('dashboard.compliance.index') }}"><x-icon name="shield"/>Compliance Register</a>
      @endif
      @if(in_array(auth()->user()->role, ['super_admin','admin'], true))
        <div class="sidebar-heading">Content & Policies</div>
        <div class="sidebar-heading">Front Customizer</div>
        <a class="{{ request()->routeIs('dashboard.appearance*') ? 'active' : '' }}" href="{{ route('dashboard.appearance') }}"><x-icon name="sun"/>Appearance</a>
        <a class="{{ request()->routeIs('dashboard.system-settings*') ? 'active' : '' }}" href="{{ route('dashboard.system-settings') }}"><x-icon name="settings"/>System Settings</a>
        <div class="sidebar-heading">Content Management</div>
        <a class="{{ request()->routeIs('dashboard.pages*') ? 'active' : '' }}" href="{{ route('dashboard.pages') }}"><x-icon name="file"/>Page Manager</a>
        <a class="{{ request()->routeIs('dashboard.posts*') ? 'active' : '' }}" href="{{ route('dashboard.posts') }}"><x-icon name="edit"/>Post Manager</a>
        <a class="{{ request()->routeIs('dashboard.policies*') ? 'active' : '' }}" href="{{ route('dashboard.policies') }}"><x-icon name="book"/>Policy Center</a>
        <div class="sidebar-heading">Marketplace</div>
        <a class="{{ request()->routeIs('dashboard.products.*') ? 'active' : '' }}" href="{{ route('dashboard.products.index') }}"><x-icon name="package"/>Product Manager</a>
        <a class="{{ request()->routeIs('dashboard.jobs.*') ? 'active' : '' }}" href="{{ route('dashboard.jobs.index') }}"><x-icon name="briefcase"/>Job Manager</a>
        <a class="{{ request()->routeIs('dashboard.contracts.index') ? 'active' : '' }}" href="{{ route('dashboard.contracts.index') }}"><x-icon name="file"/>Contract Manager</a>
        <a class="{{ request()->routeIs('dashboard.contracts.disputes','dashboard.contracts.inspect') ? 'active' : '' }}" href="{{ route('dashboard.contracts.disputes') }}"><x-icon name="shield"/>Contract Disputes</a>
        <a class="{{ request()->routeIs('dashboard.categories.*') ? 'active' : '' }}" href="{{ route('dashboard.categories.index') }}"><x-icon name="layers"/>Category Manager</a>
        <a class="{{ request()->routeIs('dashboard.marketplace.index') && request()->route('type') === 'project' ? 'active' : '' }}" href="{{ route('dashboard.marketplace.index',['type'=>'project']) }}"><x-icon name="layers"/>Project Manager</a>
        <a class="{{ request()->routeIs('dashboard.marketplace.index') && request()->route('type') === 'freelance' ? 'active' : '' }}" href="{{ route('dashboard.marketplace.index',['type'=>'freelance']) }}"><x-icon name="briefcase"/>Freelance Manager</a>
        <a class="{{ request()->routeIs('dashboard.marketplace.index') && request()->route('type') === 'service' ? 'active' : '' }}" href="{{ route('dashboard.marketplace.index',['type'=>'service']) }}"><x-icon name="settings"/>Service Manager</a>
        <a class="{{ request()->routeIs('dashboard.inquiries.*') ? 'active' : '' }}" href="{{ route('dashboard.inquiries.index') }}"><x-icon name="message"/>Service Inquiries</a>
        <div class="sidebar-heading">Communications</div>
        <a class="{{ request()->routeIs('dashboard.notifications.*') ? 'active' : '' }}" href="{{ route('dashboard.notifications.index') }}"><x-icon name="mail"/>Notification Analytics</a>
        <a class="{{ request()->routeIs('dashboard.media.*') ? 'active' : '' }}" href="{{ route('dashboard.media.index') }}"><x-icon name="image"/>Media Library</a>
        <a class="{{ request()->routeIs('dashboard.vault.*') ? 'active' : '' }}" href="{{ route('dashboard.vault.index') }}"><x-icon name="archive"/>Digital File Vault</a>
        <a class="{{ request()->routeIs('dashboard.licenses.*') ? 'active' : '' }}" href="{{ route('dashboard.licenses.index') }}"><x-icon name="scroll-text"/>License Manager</a>
        <a class="{{ request()->routeIs('dashboard.creator-applications*') ? 'active' : '' }}" href="{{ route('dashboard.creator-applications') }}"><x-icon name="user-check"/>Creator Applications</a>
        <a class="{{ request()->routeIs('dashboard.reviews') ? 'active' : '' }}" href="{{ route('dashboard.reviews') }}"><x-icon name="shield"/>Review Queue</a>
      @endif
      <div class="sidebar-heading">Customer Care</div>
      <a class="{{ request()->routeIs('dashboard.support*') ? 'active' : '' }}" href="{{ route('dashboard.support') }}"><x-icon name="message"/>Support Center</a>
    </nav>
    <div class="sidebar-bottom"><a href="{{ route('home') }}"><x-icon name="home" size="17"/> Public website</a><span>Administrator workspace</span></div>
  </aside>
  <div class="admin-main">
    <header class="admin-topbar">
      <button type="button" class="icon-button admin-menu-button" data-menu-toggle aria-label="Toggle navigation"><x-icon name="grid"/></button>
      <div><strong>@yield('title','Dashboard')</strong><small>Bambodia control center</small></div>
      <div class="topbar-right"><button type="button" class="icon-button" data-theme-toggle aria-label="Toggle theme"><x-icon name="moon"/></button>
        <a class="profile-trigger" href="{{ url('/account?='.auth()->user()->public_id) }}" aria-label="My account"><x-icon name="user"/></a>
      </div>
    </header>
    <div class="admin-content">
      @if(session('status'))<div class="notice success" role="status">{{ session('status') }}</div>@endif
      @if($errors->any())<div class="notice error" role="alert">{{ $errors->first() }}</div>@endif
      @yield('content')
    </div>
  </div>
</div>
</body>
</html>
