<?php

namespace App\Http\Middleware;

use Closure;
use App\Models\SiteSetting;
use App\Models\SiteLanguage;
use App\Models\SiteTranslationEntry;
use Illuminate\Support\Facades\Lang;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const SUPPORTED = ['en', 'zh', 'km', 'ko', 'ja', 'th', 'vi', 'de', 'fr', 'hi', 'id', 'ms', 'es'];

    public function handle(Request $request, Closure $next): Response
    {
        $pathLanguage = $request->segment(1);
        $persisted = $request->user()?->locale
            ?? $request->session()->get('locale', SiteSetting::setting('site.default_locale'));
        $active = SiteLanguage::activeCodes();
        abort_if(in_array($pathLanguage, self::SUPPORTED, true) && !in_array($pathLanguage,$active,true),404);
        $language = in_array($pathLanguage, $active, true)
            ? $pathLanguage
            : (in_array($persisted, $active, true) ? $persisted : 'en');

        App::setLocale($language);
        // DB overrides are loaded only for the current request. Untranslated
        // entries explicitly use English, even when a legacy language file exists.
        app('translator')->setLoaded([]);
        // Load native file translations before DB overlays, otherwise adding one
        // custom term could hide all remaining built-in Khmer/Chinese/etc terms.
        app('translator')->get('ui', [], $language, false);
        $english = require lang_path('en/ui.php');
        foreach (SiteTranslationEntry::query()->where('language_code',$language)->get() as $entry) {
            $translated = $entry->is_translated && trim((string)$entry->translation_value)!==''
                ? $entry->translation_value : ($english[$entry->translation_key] ?? null);
            if ($translated !== null) {
                Lang::addLines(['ui.'.$entry->translation_key=>$translated],$language);
            }
        }
        $request->attributes->set('bambodia_language', $language);

        // Every internal route/url() link renders with an explicit language
        // prefix. Never rewrite static assets, APIs, health, installer files,
        // or already prefixed URLs. Leave external links untouched.
        if (in_array($pathLanguage, self::SUPPORTED, true)) {
            URL::formatPathUsing(static function (string $path) use ($language): string {
            $path = '/'.ltrim($path, '/');
            if (preg_match('~^/('.implode('|',self::SUPPORTED).')(/|$)~', $path)
                || preg_match('~^/(api|build|storage|vendor|installer|up)(/|$)~', $path)) {
                return $path;
            }
            return '/'.$language.($path === '/' ? '' : $path);
            });
        }

        try {
            return $next($request);
        } finally {
            // Avoid leaking one request's locale to the next request in tests
            // or to an Octane-style long-running application process.
            URL::formatPathUsing(static fn (string $path): string => $path);
        }
    }
}
