<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\FeatureFlag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ListingController extends Controller
{
    public function index(Request $request): View
    {
        $listings = MarketplaceListing::query()
            ->where('user_id', $request->user()->id)
            ->latest()->paginate(10);

        return view('account.listings', ['listings' => $listings]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(FeatureFlag::isEnabled('creator_submissions'), 403);
        $data = $request->validate([
            'type' => ['required', Rule::in(MarketplaceListing::TYPES)],
            'title' => ['required', 'string', 'min:4', 'max:180'],
            'description' => ['required', 'string', 'min:40', 'max:10000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'license_terms' => ['nullable', 'string', 'max:20000', 'required_if:type,product'],
        ]);

        MarketplaceListing::query()->create([
            ...$data,
            'user_id' => $request->user()->id,
            'currency' => 'USD',
            'status' => 'pending',
        ]);

        return redirect()->route('studio.listings')->with('status', 'Listing submitted for review.');
    }
}
