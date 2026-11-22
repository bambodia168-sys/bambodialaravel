<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class MarketingPreferenceController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $request->validate(['enabled' => ['required', 'boolean']]);
        $user = $request->user();
        if ($request->boolean('enabled')) {
            User::query()->whereKey($user->id)->whereNull('marketing_subscribed_at')
                ->update(['marketing_subscribed_at' => now()]);
        } else {
            User::query()->whereKey($user->id)->update(['marketing_subscribed_at' => null]);
        }

        return back()->with('status', 'Email preference saved.');
    }

    public function confirm(Request $request, string $user): View
    {
        User::query()->where('public_id', $user)->firstOrFail(['id']);

        return view('mail.unsubscribe', ['action' => $request->getRequestUri()]);
    }

    public function unsubscribe(string $user): View
    {
        User::query()->where('public_id', $user)->update(['marketing_subscribed_at' => null]);

        return view('mail.unsubscribe', ['action' => null]);
    }
}
