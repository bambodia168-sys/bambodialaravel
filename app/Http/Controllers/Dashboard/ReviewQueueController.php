<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\AuditEvent;
use App\Models\AccountNotification;
use App\Support\ProductReadiness;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ReviewQueueController extends Controller
{
    public function index(Request $request): View
    {
        $type = (string) $request->query('type', 'all');
        abort_unless(in_array($type, ['all','product','project','service','freelance'], true), 404);
        $search = trim((string) $request->query('q', ''));
        abort_unless(mb_strlen($search) <= 120, 404);

        $listings = MarketplaceListing::query()
            ->with('creator:id,name,public_id')->where('status','pending')
            ->when($type !== 'all', fn ($q) => $q->where('type', $type))
            ->when($search !== '', fn ($q) => $q->where(function ($inner) use ($search) {
                $inner->where('title','like','%'.$search.'%')
                    ->orWhere('public_id','like','%'.$search.'%');
            }))
            ->oldest()->paginate(10)->withQueryString();

        $counts = MarketplaceListing::query()->where('status','pending')
            ->selectRaw('type, COUNT(*) as total')->groupBy('type')->pluck('total','type');
        $recentReviews = AuditEvent::query()
            ->whereIn('event',['listing.review.published','listing.review.rejected'])
            ->with('actor:id,name')->latest('created_at')->limit(6)
            ->get(['id','actor_id','event','created_at']);

        return view('dashboard.reviews',compact('listings','type','search','counts','recentReviews'));
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
            AccountNotification::deliver(
                (int)$record->user_id,'listing.reviewed','Marketplace listing reviewed',
                'A listing was '.($data['decision']==='published'?'published':'rejected').' after review.',
                $record->type==='product'?'/studio/products':'/studio/listings'
            );
            AuditEvent::record('listing.review.'.$data['decision'], $request->user(), $request);
        });

        return back()->with('status', 'Listing marked as '.$data['decision'].'.');
    }
}
