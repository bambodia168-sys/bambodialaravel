<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\SiteSocialLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class SiteSocialController extends Controller
{
    public function index(): View
    {
        return view('dashboard.site-social-links', [
            'links' => SiteSocialLink::query()->orderBy('sort_order')->orderBy('id')
                ->paginate(10)->withQueryString(),
            'platforms' => SiteSocialLink::PLATFORMS,
        ]);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'platform' => ['required', Rule::in(array_keys(SiteSocialLink::PLATFORMS))],
            'label' => ['required', 'string', 'min:2', 'max:100'],
            'url' => ['required', 'url', 'starts_with:https://', 'max:400'],
            'sort_order' => ['required', 'integer', 'between:0,1000'],
            'is_active' => ['required', 'boolean'],
        ]);
        $data['updated_by'] = $request->user()->id;
        return $data;
    }

    public function store(Request $request): RedirectResponse
    {
        SiteSocialLink::query()->create($this->validated($request));
        AuditEvent::record('site.social.created', $request->user(), $request);
        return back()->with('status', 'Footer social link added.');
    }

    public function update(Request $request, SiteSocialLink $link): RedirectResponse
    {
        $link->update($this->validated($request));
        AuditEvent::record('site.social.updated', $request->user(), $request);
        return back()->with('status', 'Footer social link updated.');
    }

    public function destroy(Request $request, SiteSocialLink $link): RedirectResponse
    {
        $link->delete();
        AuditEvent::record('site.social.deleted', $request->user(), $request);
        return back()->with('status', 'Footer social link deleted.');
    }
}
