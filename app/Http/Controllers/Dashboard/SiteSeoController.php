<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Models\AuditEvent;
use App\Models\SiteSeoPage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SiteSeoController extends Controller
{
    private function validated(Request $request, ?SiteSeoPage $page = null): array
    {
        $data = $request->validate([
            'path' => ['required', 'string', 'max:180', 'regex:~^/(?:[a-zA-Z0-9_-]+/?)*$~'],
            'locale' => ['required', Rule::in(SetLocale::SUPPORTED)],
            'title' => ['required', 'string', 'min:4', 'max:180'],
            'description' => ['required', 'string', 'min:12', 'max:320'],
            'keywords' => ['nullable', 'string', 'max:400'],
            'canonical_url' => ['nullable', 'url', 'starts_with:https://', 'max:400'],
            'og_image_url' => ['nullable', 'url', 'starts_with:https://', 'max:400'],
            'is_indexable' => ['required', 'boolean'],
            'is_active' => ['required', 'boolean'],
        ]);
        $path = rtrim($data['path'], '/') ?: '/';
        $first = explode('/', ltrim($path, '/'))[0] ?? '';
        abort_if(in_array($first, [
            'dashboard','account','studio','developer','api','login','register',
            'forgot-password','reset-password','logout','storage','build','installer',
        ], true) || in_array($first, SetLocale::SUPPORTED, true), 422, 'SEO overrides must target public, unprefixed routes.');

        $duplicate = SiteSeoPage::query()->where('path', $path)->where('locale', $data['locale'])
            ->when($page, fn ($q) => $q->where('id', '!=', $page->id))->exists();
        abort_if($duplicate, 422, 'This path already has an SEO entry in the selected language.');

        if ($data['canonical_url'] ?? false) {
            $canonicalHost = parse_url($data['canonical_url'], PHP_URL_HOST);
            $siteHost = parse_url(config('app.url'), PHP_URL_HOST);
            abort_unless(strcasecmp((string) $canonicalHost, (string) $siteHost) === 0, 422,
                'Canonical URLs must use this website host.');
        }
        $data['path'] = $path;
        $data['keywords'] = $data['keywords'] ?? null;
        $data['canonical_url'] = $data['canonical_url'] ?? null;
        $data['og_image_url'] = $data['og_image_url'] ?? null;
        $data['updated_by'] = $request->user()->id;
        return $data;
    }

    public function store(Request $request): RedirectResponse
    {
        SiteSeoPage::query()->create($this->validated($request));
        AuditEvent::record('site.seo.created', $request->user(), $request);
        return back()->with('status', 'Page SEO metadata created.');
    }

    public function update(Request $request, SiteSeoPage $page): RedirectResponse
    {
        $page->update($this->validated($request, $page));
        AuditEvent::record('site.seo.updated', $request->user(), $request);
        return back()->with('status', 'Page SEO metadata updated.');
    }

    public function destroy(Request $request, SiteSeoPage $page): RedirectResponse
    {
        $page->delete();
        AuditEvent::record('site.seo.deleted', $request->user(), $request);
        return back()->with('status', 'SEO override removed; public pages use their normal fallback.');
    }
}
