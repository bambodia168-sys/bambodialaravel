<?php

namespace App\Http\Controllers;

use App\Models\AccountNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountNotificationController extends Controller
{
    public function index(Request $request): View
    {
        $filter=(string)$request->query('filter','all');
        abort_unless(in_array($filter,['all','unread','read'],true),404);
        $event=(string)$request->query('event','all');
        abort_unless($event==='all' || in_array($event,AccountNotification::EVENTS,true),404);
        $owned=AccountNotification::query()->where('user_id',$request->user()->id);
        $notifications=(clone $owned)
            ->when($filter==='unread',fn($q)=>$q->whereNull('read_at'))
            ->when($filter==='read',fn($q)=>$q->whereNotNull('read_at'))
            ->when($event!=='all',fn($q)=>$q->where('event',$event))
            ->latest()->paginate(10)->withQueryString();
        $unread=(clone $owned)->whereNull('read_at')->count();
        $events=(clone $owned)->selectRaw('event, COUNT(*) as total')
            ->groupBy('event')->orderBy('event')->pluck('total','event');
        return view('account.notifications',compact('notifications','unread','filter','event','events'));
    }

    public function read(Request $request, AccountNotification $notification): RedirectResponse
    {
        abort_unless((int)$notification->user_id===(int)$request->user()->id,404);
        if ($notification->read_at===null) {
            $notification->update(['read_at'=>now()]);
        }
        return redirect($notification->target_path);
    }

    public function readAll(Request $request): RedirectResponse
    {
        AccountNotification::query()->where('user_id',$request->user()->id)
            ->whereNull('read_at')->update(['read_at'=>now()]);
        return back()->with('status','All notifications marked as read.');
    }
}
