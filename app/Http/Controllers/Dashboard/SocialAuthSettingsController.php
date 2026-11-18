<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\SocialLoginProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

final class SocialAuthSettingsController extends Controller
{
    public function index(): View
    {
        $configs = SocialLoginProvider::query()->get(['provider', 'client_id', 'enabled'])->keyBy('provider');

        return view('dashboard.social-auth', compact('configs'));
    }

    public function configure(Request $request, string $provider): RedirectResponse
    {
        abort_unless(isset(SocialLoginProvider::PROVIDERS[$provider]), 404);
        $bag = 'social-auth-'.$provider;
        $input = $request->validateWithBag($bag, [
            'client_id' => ['required', 'string', 'max:255'],
            'client_secret' => ['nullable', 'string', 'max:1024'],
        ]);

        DB::transaction(function () use ($request, $provider, $input, $bag): void {
            $setting = SocialLoginProvider::query()->lockForUpdate()->find($provider) ?? new SocialLoginProvider(['provider' => $provider]);
            $secret = $input['client_secret'] ?? null;
            if ($secret === null && (! $setting->exists || $setting->client_id !== $input['client_id'])) {
                throw ValidationException::withMessages(['client_secret' => 'Enter the secret for this client ID.'])
                    ->errorBag($bag);
            }
            $setting->client_id = $input['client_id'];
            if ($secret !== null) {
                $setting->client_secret = $secret;
            }
            if (! $setting->exists || $setting->isDirty(['client_id', 'client_secret'])) {
                $setting->enabled = false;
            }
            $setting->save();
            AuditEvent::record('site.setting.changed', $request->user(), $request);
        });

        return back()->with('status', SocialLoginProvider::PROVIDERS[$provider][0].' credentials saved.');
    }

    public function status(Request $request, string $provider): RedirectResponse
    {
        abort_unless(isset(SocialLoginProvider::PROVIDERS[$provider]), 404);
        $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = $request->boolean('enabled');
        if ($enabled) {
            abort_unless(parse_url(config('app.url'), PHP_URL_SCHEME) === 'https'
                || app()->environment(['local', 'testing']), 422, 'Configure an HTTPS application URL first.');
        }
        DB::transaction(function () use ($request, $provider, $enabled): void {
            $setting = SocialLoginProvider::query()->lockForUpdate()->findOrFail($provider);
            abort_if($enabled && (blank($setting->client_id) || blank($setting->client_secret)), 422, 'Save credentials first.');
            $setting->update(['enabled' => $enabled]);
            AuditEvent::record('site.setting.changed', $request->user(), $request);
        });

        return back()->with('status', SocialLoginProvider::PROVIDERS[$provider][0].' sign-in updated.');
    }
}
