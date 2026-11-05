<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\MarketplaceListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListingApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        AuditEvent::record('developer.api.listings.read', $request->attributes->get('bambodia_api_owner'), $request);

        $listings = MarketplaceListing::query()
            ->where('status', 'published')
            ->latest()->paginate(10);

        return response()->json([
            'data' => $listings->getCollection()->map(fn (MarketplaceListing $item) => [
                'public_id' => $item->public_id,
                'type' => $item->type,
                'title' => $item->title,
                'url' => $item->publicUrl(),
                'published_at' => $item->updated_at?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $listings->currentPage(),
                'last_page' => $listings->lastPage(),
                'per_page' => 10,
                'total' => $listings->total(),
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
