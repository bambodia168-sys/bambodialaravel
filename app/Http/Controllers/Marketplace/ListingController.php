<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ListingController extends Controller
{
    public function search(Request $request): View
    {
        $type = (string) $request->query('type', '');
        $query = mb_substr(trim((string) $request->query('q', '')), 0, 120);
        abort_if($type !== '' && ! in_array($type, MarketplaceListing::TYPES, true), 404);

        $listings = MarketplaceListing::query()
            ->with('creator:id,name,public_id')
            ->where('status', 'published')
            ->when($type !== '', fn ($builder) => $builder->where('type', $type))
            ->when($query !== '', fn ($builder) => $builder->where(function ($builder) use ($query): void {
                $builder->where('title', 'like', '%'.$query.'%')
                    ->orWhere('description', 'like', '%'.$query.'%');
            }))
            ->latest()->paginate(10)->withQueryString();

        return view('marketplace.search', compact('listings', 'type', 'query'));
    }

    public function show(Request $request, string $type): View
    {
        abort_unless(in_array($type, MarketplaceListing::TYPES, true), 404);
        $raw = (string) $request->server('QUERY_STRING', '');
        abort_unless(preg_match('/^=(\d{9})-([a-z0-9-]+)$/', $raw, $match) === 1, 404);

        $listing = MarketplaceListing::query()
            ->with('creator:id,name,public_id')
            ->where('public_id', $match[1])
            ->where('slug', $match[2])
            ->where('type', $type)
            ->where('status', 'published')
            ->firstOrFail();

        return view('marketplace.detail', compact('listing'));
    }
}
