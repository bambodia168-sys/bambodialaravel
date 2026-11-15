<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceJob;
use Illuminate\Http\RedirectResponse;
use App\Support\BambodiaTaxonomy;
use App\Support\MarketplaceManagement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class JobManagerController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless(MarketplaceManagement::mayView($request->user()), 403);
        $query = MarketplaceJob::query()->with(['client:id,public_id,name', 'featuredAsset']);
        $filters = MarketplaceManagement::applyFilters($query, $request, 'job', [
            'all' => 'All statuses', ...MarketplaceManagement::STATUSES, 'withdrawn' => 'Withdrawn', 'closed' => 'Closed',
        ]);
        $jobs = $query->latest()->paginate(10)->withQueryString();

        return view('dashboard.jobs', [...$filters, 'jobs' => $jobs, 'jobCategories' => BambodiaTaxonomy::jobFilterChoices()]);
    }

    public function decide(Request $request, MarketplaceJob $job): RedirectResponse
    {
        $data=$request->validate(['decision'=>['required',Rule::in(['published','rejected'])]]);
        DB::transaction(function()use($job,$data,$request){
            $locked=MarketplaceJob::query()->lockForUpdate()->findOrFail($job->id);
            MarketplaceManagement::changeStatus($locked, $data['decision'], $request);
        });
        return back()->with('status','Job review recorded.');
    }
}
