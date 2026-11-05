<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const SUPPORTED = ['en', 'zh', 'km', 'ko', 'ja', 'th', 'vi', 'de'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale ?? $request->session()->get('locale', 'en');
        App::setLocale(in_array($locale, self::SUPPORTED, true) ? $locale : 'en');

        return $next($request);
    }
}
