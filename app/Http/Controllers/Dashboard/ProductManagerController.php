<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use Illuminate\Http\RedirectResponse;
use App\Support\MarketplaceManagement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductManagerController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(MarketplaceManagement::mayView($request->user()), 403);
        $query = MarketplaceListing::query()->where('type', 'product')->with(['creator:id,public_id,name', 'category:id,name', 'licenseDefinition', 'coverAsset', 'featuredAsset']);
        $filters = MarketplaceManagement::applyFilters($query, $request, 'product', [
            'all' => 'All statuses', 'review_queue' => 'Awaiting review', ...MarketplaceManagement::STATUSES, 'withdrawn' => 'Withdrawn',
        ]);
        $products = $query->latest()->paginate(10)->withQueryString();

        return view('dashboard.products', [ ...$filters, 'products' => $products ]);
    }

    public function decide(Request $request, MarketplaceListing $product): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['published', 'rejected'])]]);

        DB::transaction(function () use ($product, $data, $request): void {
            $product = MarketplaceListing::query()->lockForUpdate()->findOrFail($product->id);
            abort_unless($product->type === 'product', 404);
            MarketplaceManagement::changeStatus($product, $data['decision'], $request);
        });

        return back()->with('status', 'Product '.$data['decision'].'.');
    }
}
