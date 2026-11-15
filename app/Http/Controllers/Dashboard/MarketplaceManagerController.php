<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Support\MarketplaceManagement;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarketplaceManagerController extends Controller
{
    public function index(Request $request, string $type): View
    {
        abort_unless(MarketplaceManagement::mayView($request->user()), 403);
        $query = MarketplaceListing::query()->where('type', $type)->with(['creator:id,public_id,name', 'category:id,name', 'featuredAsset']);
        $filters = MarketplaceManagement::applyFilters($query, $request, $type, [
            'all' => 'All statuses', ...MarketplaceManagement::STATUSES, 'withdrawn' => 'Withdrawn',
        ]);
        $listings = $query->latest()->paginate(10)->withQueryString();

        return view('dashboard.marketplace', [ ...$filters, 'listings' => $listings ]);
    }
}
