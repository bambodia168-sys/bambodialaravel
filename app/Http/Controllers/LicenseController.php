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

        $license = LicenseDefinition::query()
            ->where('public_id', $parts[1])
            ->where('slug', $parts[2])
            ->where('status', 'active')
            ->firstOrFail();

        return view('licenses.show', compact('license'));
    }
}
