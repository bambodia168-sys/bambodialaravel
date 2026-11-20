<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\MediaAsset;
use App\Models\SiteSetting;
use App\Support\VideoPlayerSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class VideoPlayerSettingsController extends Controller
{
    public function show(): View
    {
        return view('dashboard.player-settings', [
            'settings' => VideoPlayerSettings::read(),
            'preview' => MediaAsset::query()
                ->whereIn('mime_type', ['video/mp4', 'video/webm'])
                ->latest('id')->first(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $type = $request->validate(['type' => ['required', Rule::in(array_keys(VideoPlayerSettings::TYPES))]])['type'];
        $prefix = 'profiles.'.$type;
        $ad = $prefix.'.ad';
        $enabled = $request->boolean($ad.'.enabled');
        $format = $request->input($ad.'.format');
        $url = ['nullable', 'string', 'max:2000', 'url:https', function ($attribute, $value, $fail) {
            $parts = parse_url($value);
            if (isset($parts['user']) || isset($parts['pass'])) {
                $fail('Enter a public HTTPS URL without embedded credentials.');
            }
        }];
        $validated = $request->validate([
            $prefix.'.skin' => ['required', Rule::in(array_keys(VideoPlayerSettings::SKINS))],
            $prefix.'.controls' => 'sometimes|array',
            $prefix.'.controls.*' => ['distinct', Rule::in(VideoPlayerSettings::CONTROLS[$type])],
            $prefix.'.logo_url' => $url,
            $prefix.'.watermark_url' => $url,
            $prefix.'.overlay_text' => 'nullable|string|max:100',
            $prefix.'.brand_position' => 'required|in:top-left,top-right',
            $ad.'.enabled' => 'sometimes|boolean',
            $ad.'.provider' => ['required', Rule::in(array_keys(VideoPlayerSettings::AD_PROVIDERS))],
            $ad.'.format' => ['required', Rule::in(array_keys(VideoPlayerSettings::AD_FORMATS))],
            $ad.'.media_url' => [Rule::requiredIf($enabled && $format !== 'text'), ...$url],
            $ad.'.text' => [Rule::requiredIf($enabled && $format === 'text'), 'nullable', 'string', 'max:240'],
            $ad.'.link_url' => $url,
            $ad.'.duration' => 'required|integer|min:3|max:30',
        ]);
        $profile = data_get($validated, $prefix);
        $profile['controls'] = array_values($profile['controls'] ?? []);
        $profile['ad']['enabled'] = $enabled;

        if ($format === 'google_tag') {
            $tag = parse_url($profile['ad']['media_url'] ?? '');
            if ($profile['ad']['provider'] !== 'google' || ($enabled && (
                strtolower($tag['host'] ?? '') !== 'pubads.g.doubleclick.net'
                || ($tag['path'] ?? '') !== '/gampad/ads'
                || isset($tag['port'])
            ))) {
                throw ValidationException::withMessages([
                    $ad.'.media_url' => 'Enter a Google Ad Manager video tag from pubads.g.doubleclick.net/gampad/ads.',
                ]);
            }
        }

        DB::transaction(function () use ($request, $type, $profile): void {
            $setting = SiteSetting::query()->whereKey(VideoPlayerSettings::KEY)->lockForUpdate()->first();
            $settings = VideoPlayerSettings::decode($setting?->value);
            $settings['type'] = $type;
            $settings['profiles'][$type] = array_replace(
                VideoPlayerSettings::defaults(),
                $profile,
            );
            SiteSetting::query()->updateOrCreate(['key' => VideoPlayerSettings::KEY], [
                'value' => json_encode($settings, JSON_THROW_ON_ERROR),
                'updated_by' => $request->user()->getKey(),
            ]);
            AuditEvent::record('site.setting.changed', $request->user(), $request);
        }, 3);

        return back()->with('status', 'Player settings saved.');
    }
}
