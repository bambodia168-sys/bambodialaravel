<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\SiteSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const SUPPORTED = ['en', 'zh', 'km', 'ko', 'ja', 'th', 'vi', 'de'];

    public function handle(Request $request, Closure $next): Response
    {
        $pathLanguage = $request->segment(1);
        $persisted = $request->user()?->locale
            ?? $request->session()->get('locale', SiteSetting::setting('site.default_locale'));
        $language = in_array($pathLanguage, self::SUPPORTED, true)
            ? $pathLanguage
            : (in_array($persisted, self::SUPPORTED, true) ? $persisted : 'en');

        App::setLocale($language);
        $request->attributes->set('bambodia_language', $language);

        // Every internal route/url() link renders with an explicit language
        // prefix. Never rewrite static assets, APIs, health, installer files,
        // or already prefixed URLs. Leave external links untouched.
        URL::formatPathUsing(static function (string $path) use ($language): string {
            $path = '/'.ltrim($path, '/');
            if (preg_match('~^/(en|zh|km|ko|ja|th|vi|de)(/|$)~', $path)
                || preg_match('~^/(api|build|storage|vendor|installer|up)(/|$)~', $path)) {
                return $path;
            }
            return '/'.$language.($path === '/' ? '' : $path);
        });

        try {
            return $next($request);
        } finally {
            // Avoid leaking one request's locale to the next request in tests
            // or to an Octane-style long-running application process.
            URL::formatPathUsing(static fn (string $path): string => $path);
        }
    }
}
