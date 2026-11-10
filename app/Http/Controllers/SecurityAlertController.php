<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\SecurityAlert;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class SecurityAlertController extends Controller
{
    public function acknowledge(Request $request, SecurityAlert $alert): RedirectResponse
    {
        if ($alert->acknowledged_at === null) {
            // Atomic update prevents concurrent acknowledgement overwrites.
            $changed = SecurityAlert::query()
                ->whereKey($alert->id)
                ->whereNull('acknowledged_at')
                ->update([
                    'acknowledged_at' => now(),
                    'acknowledged_by' => $request->user()->id,
                    'updated_at' => now(),
                ]);
            if ($changed) {
                AuditEvent::record('security.alert.acknowledged', $request->user(), $request);
            }
        }

        return back()->with('status', 'Security alert acknowledged.');
    }
}
