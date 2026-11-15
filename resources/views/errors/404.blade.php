{{-- Serve the same self-contained document for Laravel and web-server 404s. --}}
{!! file_get_contents(public_path('404.html')) !!}
