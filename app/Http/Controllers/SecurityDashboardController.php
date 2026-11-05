<?php

namespace App\Http\Controllers;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SecurityDashboardController extends Controller
{
    public function index(Request $request): View
    {
        $type = (string) $request->query('event', '');
        abort_if($type !== '' && ! in_array($type, AuditEvent::EVENTS, true), 404);

        $events = AuditEvent::query()->with('actor:id,name,public_id')
            ->when($type !== '', fn ($builder) => $builder->where('event', $type))
            ->latest('id')->paginate(10)->withQueryString();

        $since = now()->subDay();

        return view('security.index', [
            'events' => $events,
            'eventFilter' => $type,
            'availableEvents' => AuditEvent::EVENTS,
            'eventsToday' => AuditEvent::query()->where('created_at', '>=', $since)->count(),
            'failedLoginsToday' => AuditEvent::query()->where('event', 'auth.login.failed')->where('created_at', '>=', $since)->count(),
            'activeTokens' => ApiToken::query()->whereNull('revoked_at')->where('expires_at', '>', now())->count(),
        ]);
    }
}
