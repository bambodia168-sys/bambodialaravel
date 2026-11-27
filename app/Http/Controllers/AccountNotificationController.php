<?php

namespace App\Http\Controllers;

use App\Models\AccountNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AccountNotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $filter=(string)$request->query('filter','all');
        abort_unless(in_array($filter,['all','unread','read'],true),404);
        $event=(string)$request->query('event','all');
        abort_unless($event==='all' || in_array($event,AccountNotification::EVENTS,true),404);
        $owned=AccountNotification::query()->forRecipient($request->user());
        $notifications=(clone $owned)
            ->when($filter==='unread',fn($q)=>$q->whereNull('read_at'))
            ->when($filter==='read',fn($q)=>$q->whereNotNull('read_at'))
            ->when($event!=='all',fn($q)=>$q->where('event',$event))
            ->latest()->latest('id')->paginate(10)->withQueryString();
        $unread=(clone $owned)->whereNull('read_at')->count();
        $events=(clone $owned)->selectRaw('event, COUNT(*) as total')
            ->groupBy('event')->orderBy('event')->pluck('total','event');
        return response()->view('account.notifications', compact('notifications','unread','filter','event','events'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function tray(Request $request): Response
    {
        return response()->view('account.partials.notification-tray', AccountNotification::unreadTray($request->user()))
            ->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, AccountNotification $notification): RedirectResponse
    {
        $owned = AccountNotification::query()->forRecipient($request->user())->whereKey($notification->id);
        $notification = (clone $owned)->firstOrFail();
        $owned->whereNull('read_at')->update(['read_at' => now()]);

        // Resolve old verification notices by event rather than their obsolete cart URL.
        if (in_array($notification->event, ['account.email.verification_sent', 'account.email.verified'], true)) {
            return redirect()->route($request->user()->hasVerifiedEmail() ? 'verification.verified' : 'verification.notice');
        }

        return AccountNotification::isLocalTarget($notification->target_path)
            ? redirect($notification->target_path)
            : redirect()->route('account.notifications');
    }

    public function readAll(Request $request): RedirectResponse
    {
        AccountNotification::query()->forRecipient($request->user())
            ->whereNull('read_at')->update(['read_at'=>now()]);
        return back()->with('status','All notifications marked as read.');
    }
}
