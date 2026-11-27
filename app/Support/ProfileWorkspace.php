<?php

namespace App\Support;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Resolve self-profile destinations from trusted route names, never request input. */
final class ProfileWorkspace
{
    public static function prefix(Request $request): string
    {
        return match (true) {
            $request->routeIs('dashboard.profile.*', 'localized.*.dashboard.profile.*') => 'dashboard.profile',
            $request->routeIs('studio.profile.*', 'localized.*.studio.profile.*') => 'studio.profile',
            default => 'account.profile',
        };
    }

    public static function redirect(Request $request, string $message): RedirectResponse
    {
        $prefix = self::prefix($request);

        return ($prefix === 'account.profile' ? back() : redirect()->route($prefix.'.edit'))
            ->with('status', $message);
    }
}
