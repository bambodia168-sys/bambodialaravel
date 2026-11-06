<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\ContractDispute;
use App\Models\CreatorApplication;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $isStaff = request()->user()->role === 'staff';
        $supportCount = SupportTicket::query()->whereIn('status', ['open', 'in_progress'])->count();
        $queues = [
            ['label' => 'Unresolved support tickets', 'count' => $supportCount,
             'route' => 'dashboard.support', 'icon' => 'life-buoy'],
        ];

        $stats = [];
        if (! $isStaff) {
            $stats = [
                'totalUsers' => User::query()->count(),
                'creators' => User::query()->where('role', 'freelancer')->count(),
                'customers' => User::query()->whereIn('role', ['customer', 'subscriber'])->count(),
            ];
            $queues = array_merge([
                ['label' => 'Pending creator applications',
                 'count' => CreatorApplication::query()->where('status', 'pending')->count(),
                 'route' => 'dashboard.creator-applications', 'icon' => 'user-round-check'],
                ['label' => 'All listings awaiting review',
                 'count' => MarketplaceListing::query()->where('status', 'pending')->count(),
                 'route' => 'dashboard.reviews', 'icon' => 'layers'],
                ['label' => 'Digital products awaiting review',
                 'count' => MarketplaceListing::query()->where('status', 'pending')->where('type', 'product')->count(),
                 'route' => 'dashboard.products.index', 'params' => ['status' => 'pending'], 'icon' => 'package'],
                ['label' => 'Job posts awaiting review',
                 'count' => MarketplaceJob::query()->where('status', 'pending')->count(),
                 'route' => 'dashboard.jobs.index', 'icon' => 'briefcase'],
                ['label' => 'Unresolved contract disputes',
                 'count' => ContractDispute::query()->whereIn('status', ['open', 'under_review'])->count(),
                 'route' => 'dashboard.contracts.disputes', 'icon' => 'shield'],
            ], $queues);
        }

        // Staff only see the support queue, never platform-wide account or moderation data.
        $recent = $isStaff
            ? SupportTicket::query()->whereIn('status', ['open', 'in_progress'])
                ->latest('updated_at')->limit(5)->get(['id', 'public_id', 'subject', 'status', 'updated_at'])
            : MarketplaceListing::query()->where('status', 'pending')
                ->latest()->limit(5)->get(['id', 'type', 'title', 'status', 'created_at']);

        return view('dashboard.index', compact('isStaff', 'stats', 'queues', 'recent'));
    }
}
