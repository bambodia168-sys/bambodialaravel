<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceListing;
use App\Models\FeatureFlag;
use App\Models\MarketplaceCategory;
use App\Models\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ListingController extends Controller
{
    public function index(Request $request): View
    {
        $listings = MarketplaceListing::query()
            ->where('user_id', $request->user()->id)
            ->latest()->paginate(10);

        $categories=MarketplaceCategory::query()->where('is_active',true)
            ->whereIn('type',['project','freelance','service'])
            ->orderBy('type')->orderBy('sort_order')->get(['id','type','name']);
        return view('account.listings', ['listings' => $listings,'categories'=>$categories]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(FeatureFlag::isEnabled('creator_submissions'), 403);
        $data = $request->validate([
            'type' => ['required', Rule::in(['project', 'freelance', 'service'])],
            'title' => ['required', 'string', 'min:4', 'max:180'],
            'description' => ['required', 'string', 'min:40', 'max:10000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'license_terms' => ['nullable', 'string', 'max:20000'],
            'category_id' => ['nullable','integer',Rule::exists('marketplace_categories','id')],
        ]);

        $this->checkCategory($data);
        MarketplaceListing::query()->create([
            ...$data,
            'user_id' => $request->user()->id,
            'currency' => 'USD',
            'status' => 'pending',
        ]);

        return redirect()->route('studio.listings')->with('status', 'Listing submitted for review.');
    }

    public function update(Request $request,MarketplaceListing $listing): RedirectResponse
    {
        abort_unless(FeatureFlag::isEnabled('creator_submissions'),403);
        $data=$request->validate([
            'title'=>['required','string','min:4','max:180'],
            'description'=>['required','string','min:40','max:10000'],
            'price'=>['nullable','numeric','min:0','max:9999999999.99'],
            'license_terms'=>['nullable','string','max:20000'],
            'category_id'=>['nullable','integer',Rule::exists('marketplace_categories','id')],
        ]);
        DB::transaction(function()use($request,$listing,$data){
            $locked=MarketplaceListing::query()->lockForUpdate()->findOrFail($listing->id);
            abort_unless((int)$locked->user_id===(int)$request->user()->id
                && in_array($locked->type,['project','freelance','service'],true),404);
            abort_unless(in_array($locked->status,['draft','rejected'],true),409);
            $this->checkCategory([...$data,'type'=>$locked->type]);
            $locked->update([...$data,'status'=>'pending']);
            AuditEvent::record('listing.resubmitted',$request->user(),$request);
        });
        return back()->with('status','Listing changes resubmitted for review.');
    }

    private function checkCategory(array $data): void
    {
        if(empty($data['category_id'])){return;}
        if(!MarketplaceCategory::query()->whereKey($data['category_id'])
            ->where('type',$data['type'])->where('is_active',true)->exists()){
            throw ValidationException::withMessages(['category_id'=>'Select an active category for the listing type.']);
        }
    }
}

