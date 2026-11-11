<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AiDraftProvider;
use App\Models\AiDraftRequest;
use App\Models\AuditEvent;
use App\Support\AiDraftStudio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class AiDraftSettingsController extends Controller
{
    public function index(): View
    {
        $provider = AiDraftProvider::configured();
        $today = now()->startOfDay();
        return view('dashboard.ai-draft-settings', [
            'provider' => $provider,
            'models' => AiDraftProvider::MODELS,
            'usage' => AiDraftRequest::query()->with('author:id,name')
                ->latest('id')->paginate(10)->withQueryString(),
            'usageToday' => AiDraftRequest::query()
                ->where('created_at', '>=', $today)->count(),
            'completedToday' => AiDraftRequest::query()
                ->where('created_at', '>=', $today)->where('status', 'completed')->count(),
            'reviewedToday' => AiDraftRequest::query()
                ->where('created_at', '>=', $today)->whereNotNull('reviewed_at')->count(),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'model' => ['required', Rule::in(array_keys(AiDraftProvider::MODELS))],
            'api_key' => ['nullable', 'string', 'min:20', 'max:500'],
            'daily_limit' => ['required', 'integer', 'between:1,30'],
            'global_daily_limit' => ['required', 'integer', 'between:1,200'],
        ]);
        $existing = AiDraftProvider::configured();
        $key = (string) ($data['api_key'] ?? '');
        $keyChanged = $key !== '';
        $modelChanged = $existing?->model !== $data['model'];

        if (! $keyChanged) {
            $key = $existing?->api_key ?? '';
        }
        if (strlen($key) < 20) {
            return back()->withErrors(['api_key' => 'A provider API key is required before saving.']);
        }

        $requiresRetest = $keyChanged || $modelChanged;
        AiDraftProvider::query()->updateOrCreate(['id' => 1], [
            'provider' => 'openai',
            'model' => $data['model'],
            'api_key' => $key,
            'daily_limit' => (int) $data['daily_limit'],
            'global_daily_limit' => (int) $data['global_daily_limit'],
            'is_enabled' => $requiresRetest ? false : (bool) ($existing?->is_enabled ?? false),
            'verified_at' => $requiresRetest ? null : $existing?->verified_at,
            'updated_by' => $request->user()->id,
        ]);
        AuditEvent::record('ai.provider.configured', $request->user(), $request);

        return back()->with('status',
            $requiresRetest ? 'Provider saved encrypted. Test the connection before enabling.'
                : 'AI usage limits updated. Existing provider state preserved.');
    }

    public function test(Request $request, AiDraftStudio $studio): RedirectResponse
    {
        $provider = AiDraftProvider::configured();
        abort_unless($provider && $provider->api_key, 404);

        if (! $studio->testConnection($provider)) {
            return back()->withErrors([
                'provider' => 'AI provider connection failed. Verify model permissions, key and outbound HTTPS access.',
            ]);
        }

        $provider->update(['verified_at' => now()]);
        AuditEvent::record('ai.provider.tested', $request->user(), $request);
        return back()->with('status', 'Provider responded successfully. You can now enable AI drafting.');
    }

    public function status(Request $request): RedirectResponse
    {
        $data = $request->validate(['is_enabled' => ['required','boolean']]);
        $provider = AiDraftProvider::configured();
        abort_unless($provider, 404);

        if ((bool) $data['is_enabled']) {
            abort_unless(
                $provider->verified_at?->greaterThan(now()->subDay())
                && (bool) $provider->api_key,
                422, 'Test the provider connection within the previous 24 hours before enabling.'
            );
        }

        $provider->update([
            'is_enabled' => (bool) $data['is_enabled'],
            'updated_by' => $request->user()->id,
        ]);
        AuditEvent::record('ai.provider.status.changed', $request->user(), $request);
        return back()->with('status', $provider->is_enabled
            ? 'AI drafting enabled with enforced daily limits.'
            : 'AI drafting disabled. Existing creator content is unchanged.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $provider = AiDraftProvider::configured();
        abort_unless($provider, 404);
        $provider->update([
            'api_key' => null,
            'verified_at' => null,
            'is_enabled' => false,
            'updated_by' => $request->user()->id,
        ]);
        AuditEvent::record('ai.provider.disconnected', $request->user(), $request);
        return back()->with('status', 'Provider key removed. Metadata-only usage history is retained.');
    }
}
