<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\MarketplaceListing;
use App\Support\ProductReadiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductManagerController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'all');
        abort_unless(in_array($status, ['all', 'draft', 'pending', 'published', 'rejected'], true), 404);
        $search = mb_substr(trim((string) $request->query('search', '')), 0, 120);

        $products = MarketplaceListing::query()
            ->where('type', 'product')
            ->with(['creator:id,name,public_id', 'licenseDefinition:id,name,version,status', 'coverAsset:id,public_id,alt_text,review_status'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($search !== '', fn ($q) => $q->where(function ($q) use ($search): void {
                $q->where('title', 'like', '%'.$search.'%')->orWhere('public_id', 'like', '%'.$search.'%');
            }))
            ->latest()->paginate(10)->withQueryString();

        return view('dashboard.products', compact('products', 'status', 'search'));
    }

    public function decide(Request $request, MarketplaceListing $product): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['published', 'rejected'])]]);

        DB::transaction(function () use ($product, $data, $request): void {
            $product = MarketplaceListing::query()->lockForUpdate()->findOrFail($product->id);
            abort_unless($product->type === 'product', 404);
            abort_unless($product->status === 'pending', 409, 'Only pending products can be reviewed.');
            if ($data['decision'] === 'published') {
                ProductReadiness::assertListing($product);
            }
            $product->update(['status' => $data['decision']]);
            AuditEvent::record('listing.review.'.$data['decision'], $request->user(), $request);
        });

        return back()->with('status', 'Product '.$data['decision'].'.');
    }
}
