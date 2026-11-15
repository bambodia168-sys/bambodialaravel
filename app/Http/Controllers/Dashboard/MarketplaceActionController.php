<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Http\Controllers\MediaLibraryController;
use App\Models\MediaAsset;
use App\Support\MarketplaceManagement;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

final class MarketplaceActionController extends Controller
{
    public function status(Request $request, string $type, string $publicId): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(MarketplaceManagement::STATUSES))],
            'expected_status' => ['required', 'string', 'max:20'],
        ]);

        DB::transaction(function () use ($request, $type, $publicId, $data): void {
            $record = MarketplaceManagement::record($type, $publicId, true);
            abort_unless($record->status === $data['expected_status'], 409, 'The status changed. Refresh the manager and try again.');
            MarketplaceManagement::changeStatus($record, $data['status'], $request);
        }, 3);

        return redirect(MarketplaceManagement::indexUrl($type))
            ->with('status', 'Listing status set to '.MarketplaceManagement::STATUSES[$data['status']].'.');
    }

    public function asset(Request $request, string $type, string $publicId, string $mediaId): Response
    {
        abort_unless(MarketplaceManagement::mayView($request->user()), 403);
        $record = MarketplaceManagement::record($type, $publicId);
        $asset = MediaAsset::query()->whereIn('id', MarketplaceManagement::mediaIds($record))
            ->where('public_id', $mediaId)->firstOrFail();
        return MediaLibraryController::servePreview($request, $asset);
    }
}
