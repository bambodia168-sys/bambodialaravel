<?php

namespace App\Http\Controllers;

use App\Models\LicenseDefinition;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LicenseController extends Controller
{
    public function show(Request $request): View
    {
        $raw = (string) $request->server('QUERY_STRING', '');
        abort_unless(preg_match('/^=(\d{9})-([a-z0-9-]+)$/', $raw, $parts) === 1, 404);

        $published = LicenseDefinition::query()->where('status', 'active');
        $license = (clone $published)
            ->where('public_id', $parts[1])
            ->where('slug', $parts[2])
            ->firstOrFail();

        $directory = $published
            ->orderByRaw("CASE tier WHEN 'regular' THEN 0 WHEN 'extended' THEN 1 ELSE 2 END")
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'public_id', 'slug']);

        return view('licenses.show', compact('license', 'directory'));
    }
}
