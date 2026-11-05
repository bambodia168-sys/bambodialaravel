<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\AuditEvent;
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
        abort_unless($listing->status === 'pending', 409, 'This listing is no longer pending.');

        $listing->update(['status' => $data['decision']]);
        AuditEvent::record('listing.review.'.$data['decision'], $request->user(), $request);

        return back()->with('status', 'Listing marked as '.$data['decision'].'.');
    }
}
