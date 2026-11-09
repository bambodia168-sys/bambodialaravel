<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketplaceCardFeaturesController extends Controller
{
    public function listing(Request $request, MarketplaceListing $listing): RedirectResponse
    {
        $data=$request->validate(['is_featured'=>['required','boolean']]);
        DB::transaction(function () use ($request,$listing,$data): void {
            $locked=MarketplaceListing::query()->lockForUpdate()->findOrFail($listing->id);
            abort_unless($locked->status==='published',409,'Only published listings may be featured.');
            $locked->update(['is_featured'=>(bool)$data['is_featured']]);
            AuditEvent::record('marketplace.listing.featured.updated',$request->user(),$request);
        });
        return back()->with('status','Listing Featured status updated.');
    }

    public function job(Request $request, MarketplaceJob $job): RedirectResponse
    {
        $data=$request->validate(['is_featured'=>['required','boolean']]);
        DB::transaction(function () use ($request,$job,$data): void {
            $locked=MarketplaceJob::query()->lockForUpdate()->findOrFail($job->id);
            abort_unless($locked->status==='published',409,'Only published jobs may be featured.');
            $locked->update(['is_featured'=>(bool)$data['is_featured']]);
            AuditEvent::record('marketplace.job.featured.updated',$request->user(),$request);
        });
        return back()->with('status','Job Featured status updated.');
    }
}
