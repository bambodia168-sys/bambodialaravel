@props(['name' => 'circle', 'size' => 20])
@php
$paths = [
    'github' => '<path d="M9 19c-4.5 1.5-4.5-2.5-6-3m15 6v-4a3.5 3.5 0 0 0-1-2.7c3.3-.4 6.8-1.6 6.8-7.4a5.8 5.8 0 0 0-1.6-4A5.4 5.4 0 0 0 22 1s-1.5-.4-4 1.5a14 14 0 0 0-7.5 0C8 0.6 6.5 1 6.5 1a5.4 5.4 0 0 0-.2 2.9 5.8 5.8 0 0 0-1.6 4c0 5.8 3.5 7 6.8 7.4a3.5 3.5 0 0 0-1 2.7v4"/>',
    'home' => '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1z"/>',
    'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
    'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
    'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
    'lock' => '<rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
    'shield' => '<path d="m12 2 9 4v6c0 5-3.5 8-9 10-5.5-2-9-5-9-10V6z"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
    'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    'briefcase' => '<rect x="3" y="7" width="18" height="14" rx="2"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M3 12h18"/>',
    'package' => '<path d="m12 2 9 5v10l-9 5-9-5V7zM3 7l9 5 9-5M12 12v10"/>',
    'layers' => '<path d="m12 2 9 5-9 5-9-5zM3 12l9 5 9-5M3 17l9 5 9-5"/>',
    'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l-2.86 2.86A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1.5 1.6h-3A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.88.34l-2.86-2.86A1.7 1.7 0 0 0 4.6 15 1.7 1.7 0 0 0 3 13.5v-3A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.34-1.88l2.86-2.86A1.7 1.7 0 0 0 9 4.6 1.7 1.7 0 0 0 10.5 3h3A1.7 1.7 0 0 0 15 4.6a1.7 1.7 0 0 0 1.88-.34l2.86 2.86A1.7 1.7 0 0 0 19.4 9a1.7 1.7 0 0 0 1.6 1.5v3a1.7 1.7 0 0 0-1.6 1.5z"/>',
    'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41m11.32 11.32 1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/>',
    'moon' => '<path d="M20.5 14A9 9 0 0 1 10 3.5 9 9 0 1 0 20.5 14z"/>',
    'chevron-left' => '<path d="m15 18-6-6 6-6"/>',
    'chevron-right' => '<path d="m9 18 6-6-6-6"/>',
    'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
    'edit' => '<path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L9 17l-4 1 1-4z"/>',
    'check' => '<path d="m4 12 5 5L20 6"/>',
    'globe' => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20M12 2a15 15 0 0 0 0 20"/>',
    'book' => '<path d="M4 19.5a2.5 2.5 0 0 1 2.5-2.5H20M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
    'message' => '<path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5 9 9 0 0 1-4-.9L3 21l1.9-5.5a9 9 0 0 1-.9-4A8.5 8.5 0 0 1 12.5 3 8.5 8.5 0 0 1 21 11.5z"/>',
    'alert' => '<path d="M12 2 2 21h20L12 2zM12 9v5M12 18h.01"/>',
    'file' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h8"/>',
    'filter' => '<path d="M4 7h16M7 12h10M10 17h4"/>',
    'star' => '<path d="m12 2 3.1 6.3 7 .9-5 4.9 1.2 7-6.3-3.3-6.3 3.3 1.2-7-5-4.9 7-.9z"/>',
];
@endphp
<svg width="{{ $size }}" height="{{ $size }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" {{ $attributes }}>{!! $paths[$name] ?? '<circle cx="12" cy="12" r="9"/>' !!}</svg>
