<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AccountNotification;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationAnalyticsController extends Controller
{
    public function index(Request $request): View
    {
        $since=now()->subDays(30);
        $notifications=AccountNotification::query()->with('recipient:id,name,public_id')
            ->latest()->paginate(10);
        $total=AccountNotification::query()->count();
        $unread=AccountNotification::query()->whereNull('read_at')->count();
        $last30Days=AccountNotification::query()->where('created_at','>=',$since)->count();
        $byEvent=AccountNotification::query()
            ->selectRaw('event, COUNT(*) as total')
            ->groupBy('event')->orderByDesc('total')->get();

        return view('dashboard.notifications',compact('notifications','total','unread','last30Days','byEvent'));
    }
}
