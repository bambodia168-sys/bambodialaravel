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
        abort_unless(in_array($filter,['all','unread'],true),404);
        $notifications=AccountNotification::query()->where('user_id',$request->user()->id)
            ->when($filter==='unread',fn($q)=>$q->whereNull('read_at'))
            ->latest()->paginate(10)->withQueryString();
        $unread=AccountNotification::query()->where('user_id',$request->user()->id)->whereNull('read_at')->count();
        return view('account.notifications',compact('notifications','unread','filter'));
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
