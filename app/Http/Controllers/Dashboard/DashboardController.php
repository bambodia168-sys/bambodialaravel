<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\CreatorApplication;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        return view('dashboard.index', [
            'totalUsers' => User::query()->count(),
            'creators' => User::query()->where('role', 'freelancer')->count(),
            'customers' => User::query()->where('role', 'customer')->count(),
            'creatorApplications' => CreatorApplication::query()->where('status', 'pending')->count(),
        ]);
    }
}
