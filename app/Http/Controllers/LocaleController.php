<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(SetLocale::SUPPORTED)]]);
        $language = $data['locale'];
        $request->session()->put('locale', $language);
        if ($request->user()) {
            $request->user()->update(['locale' => $language]);
        }

        // Preserve the current page, including public IDs and search filters,
        // while changing only the language prefix. Do not allow an untrusted
        // Referer to redirect to any other host.
        $previous = $request->headers->get('referer', '');
        $url = parse_url($previous);
        $currentHost = $request->getHost();
        if (is_array($url)
            && isset($url['host'])
            && strcasecmp((string) $url['host'], $currentHost) === 0
            && in_array(strtolower((string) ($url['scheme'] ?? '')), ['http', 'https'], true)
            && !isset($url['user'], $url['pass'])) {
            $path = (string) ($url['path'] ?? '/');
            $path = preg_replace('~^/(en|zh|km|ko|ja|th|vi|de)(?=/|$)~', '', $path) ?: '/';
            if (!str_starts_with($path, '/')) {
                $path = '/'.$path;
            }
            $query = isset($url['query']) ? '?'.$url['query'] : '';
            return redirect()->to(url('/'.$language.($path === '/' ? '' : $path)).$query);
        }

        return redirect()->to(url('/'.$language));
    }
}
