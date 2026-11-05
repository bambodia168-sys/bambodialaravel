<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\AuditEvent;
use App\Support\ProductReadiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReviewQueueController extends Controller
{
    public function index(): View
    {
        $listings = MarketplaceListing::query()
            ->with('creator:id,name,public_id')
            ->where('status', 'pending')
            ->oldest()->paginate(10);

        return view('dashboard.reviews', ['listings' => $listings]);
    }

    public function decide(Request $request, MarketplaceListing $listing): RedirectResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['published', 'rejected'])]]);
        DB::transaction(function () use ($listing, $data, $request): void {
            $record = MarketplaceListing::query()->lockForUpdate()->findOrFail($listing->id);
            abort_unless($record->status === 'pending', 409, 'This listing is no longer pending.');
            if ($data['decision'] === 'published') {
                ProductReadiness::assertListing($record);
            }
            $record->update(['status' => $data['decision']]);
            AuditEvent::record('listing.review.'.$data['decision'], $request->user(), $request);
        });

        return back()->with('status', 'Listing marked as '.$data['decision'].'.');
    }
}
