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

        $record = DB::transaction(function () use ($request, $type, $publicId, $data) {
            $record = MarketplaceManagement::record($type, $publicId, true);
            abort_unless($record->status === $data['expected_status'], 409, 'The status changed. Refresh the manager and try again.');
            MarketplaceManagement::changeStatus($record, $data['status'], $request);
            return $record;
        }, 3);

        if ($data['status'] === 'draft' && $request->boolean('open_editor')) {
            return redirect(MarketplaceManagement::builderUrl($record))->with('status', 'Moved to draft. You can now edit this record.');
        }

        return redirect(MarketplaceManagement::indexUrl($type, in_array($data['status'], ['archived', 'deleted'], true)
            ? ['status' => $data['status']] : []))
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
