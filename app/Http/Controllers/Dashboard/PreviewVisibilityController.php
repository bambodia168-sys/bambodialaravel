<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Support\PreviewContentReview;
use App\Support\PreviewVisibilityService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PreviewVisibilityController extends Controller
{
    public function index(Request $request): View
    {
        $kind = (string) $request->query('kind', 'listing');
        $status = (string) $request->query('status', 'published');
        abort_unless(in_array($kind, ['listing', 'job'], true), 404);
        abort_unless(in_array($status, ['published', 'withdrawn', 'all'], true), 404);

        $query = $kind === 'listing' ? MarketplaceListing::query() : MarketplaceJob::query();

        // Public-facing review inventory only, not arbitrary private drafts.
        $records = $query->whereIn('status', $status === 'all'
                ? ['published', 'withdrawn'] : [$status])
            ->where(function ($q): void {
                foreach (['[LAYOUT TEST]', '[DEMO]', '[SAMPLE]', 'Preview only'] as $marker) {
                    $q->orWhere('title', 'like', '%'.$marker.'%');
                }
            })
            ->latest('id')->paginate(10)->withQueryString();

        $reviews = [];
        foreach ($records as $record) {
            if (! PreviewContentReview::isPreviewTitle((string) $record->title)) {
                continue;
            }
            $reviews[(int) $record->id] = PreviewContentReview::record($kind, (int) $record->id);
        }

        return view('dashboard.preview-visibility', [
            'records' => $records,
            'reviews' => $reviews,
            'kind' => $kind,
            'status' => $status,
            'writesEnabled' => config('preview_visibility.enabled', false) === true,
        ]);
    }

    public function withdraw(Request $request, string $kind, string $publicId, PreviewVisibilityService $service): RedirectResponse
    {
        return $this->handle($request, $kind, $publicId, 'withdraw', $service);
    }

    public function restore(Request $request, string $kind, string $publicId, PreviewVisibilityService $service): RedirectResponse
    {
        return $this->handle($request, $kind, $publicId, 'restore', $service);
    }

    private function handle(
        Request $request, string $kind, string $publicId,
        string $action, PreviewVisibilityService $service
    ): RedirectResponse {
        $data = $request->validate([
            'confirm_public_id' => ['required', 'string', 'size:9', 'regex:/^[0-9]{9}$/D'],
            'expected_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'owner_confirmation_reference' => ['required', 'string', 'min:12', 'max:250'],
            'backup_reference' => ['required', 'string', 'min:12', 'max:350'],
            'rights_review_reference' => ['nullable', 'string', 'min:12', 'max:250'],
            'reason' => ['required', 'string', 'min:20', 'max:2000'],
            'acknowledge' => ['accepted'],
        ]);

        $service->change($kind, $publicId, $action, $data, $request->user());

        return redirect()->route('dashboard.preview-visibility.index', [
            'kind' => $kind, 'status' => $action === 'withdraw' ? 'withdrawn' : 'published',
        ])->with('status', $action === 'withdraw'
            ? 'The selected record was withdrawn. All original data is retained.'
            : 'The selected record was restored to pending review; it is not yet public.');
    }
}
