<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\FeatureFlag;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Support\ProductReadiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProductController extends Controller
{
    public function index(Request $request): View
    {
        $products = MarketplaceListing::query()
            ->where('type', 'product')
            ->where('user_id', $request->user()->id)
            ->with(['licenseDefinition:id,name,version', 'coverAsset:id,public_id,alt_text,review_status'])
            ->latest()->paginate(10);

        $licenses = LicenseDefinition::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'version']);
        $assets = MediaAsset::query()->where('user_id', $request->user()->id)
            ->where('review_status', 'approved')
            ->latest()->get(['id', 'public_id', 'alt_text']);

        return view('account.products', compact('products', 'licenses', 'assets'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(FeatureFlag::isEnabled('creator_submissions'), 403);
        $data = $this->validated($request);

        if ($data['status'] === 'pending') {
            ProductReadiness::assertReady(
                $request->user()->id,
                $data['license_definition_id'] ?? null,
                $data['cover_media_id'] ?? null
            );
        }

        MarketplaceListing::query()->create([
            ...$data,
            'user_id' => $request->user()->id,
            'type' => 'product',
            'currency' => 'USD',
        ]);

        AuditEvent::record('product.created', $request->user(), $request);

        return redirect()->route('studio.products.index')->with('status', 'Digital product saved.');
    }

    public function update(Request $request, MarketplaceListing $product): RedirectResponse
    {
        abort_unless(FeatureFlag::isEnabled('creator_submissions'), 403);
        $data = $this->validated($request);

        DB::transaction(function () use ($request, $product, $data): void {
            $record = MarketplaceListing::query()->lockForUpdate()->findOrFail($product->id);
            abort_unless($record->type === 'product' && (int) $record->user_id === (int) $request->user()->id, 404);
            abort_unless(in_array($record->status, ['draft', 'rejected'], true), 409, 'Submitted or published products cannot be edited directly.');

            if ($data['status'] === 'pending') {
                ProductReadiness::assertReady(
                    (int) $record->user_id,
                    $data['license_definition_id'] ?? null,
                    $data['cover_media_id'] ?? null
                );
            }

            $record->update($data);
            AuditEvent::record('product.updated', $request->user(), $request);
        });

        return back()->with('status', 'Product changes saved.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'min:4', 'max:180'],
            'description' => ['required', 'string', 'min:40', 'max:10000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'license_terms' => ['nullable', 'string', 'max:20000'],
            'license_definition_id' => ['nullable', 'integer', Rule::exists('license_definitions', 'id')],
            'cover_media_id' => ['nullable', 'integer', Rule::exists('media_assets', 'id')],
            'status' => ['required', Rule::in(['draft', 'pending'])],
        ]);
    }
}
