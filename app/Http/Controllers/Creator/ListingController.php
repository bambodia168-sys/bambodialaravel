<?php

namespace App\Http\Controllers\Creator;

use App\Http\Controllers\Controller;
use App\Support\BuilderContent;
use App\Support\EmailOutbox;
use Illuminate\Support\Str;
use App\Support\BuilderGallery;
use App\Support\BuilderSubmissionTerms;
use App\Support\BuilderFeaturedMedia;
use App\Support\SubmissionNotifications;
use App\Models\MarketplaceListing;
use App\Models\FeatureFlag;
use App\Models\MarketplaceCategory;
use App\Models\AuditEvent;
use App\Models\ProductEntitlement;
use App\Models\ProductLicense;
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
        $archived = $request->query('status') === 'archived';
        $listings = MarketplaceListing::query()
            ->inCreatorStudio()
            ->where('user_id', $request->user()->id)
            ->where('status', $archived ? '=' : '!=', 'archived')
            ->latest()->orderByDesc('id')->paginate(10)->withQueryString();
        $publicListingIds = MarketplaceListing::query()->publiclyAvailable()
            ->whereKey($listings->getCollection()->modelKeys())->pluck('id')->flip();

        return view('account.listings', compact('listings', 'archived', 'publicListingIds'));
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(FeatureFlag::isEnabled('creator_submissions'), 403);
        $termsHash = BuilderSubmissionTerms::requireAcceptance($request);
        BuilderContent::prepare($request);
        $data = $request->validate([
            'type' => ['required', Rule::in(['project', 'freelance', 'service'])],
            'title' => ['required', 'string', 'min:4', 'max:180'],
            'description' => ['required', 'string', 'min:40', 'max:10000'],
            ...BuilderFeaturedMedia::rules(),
            ...BuilderGallery::rules(),
            'description_html' => ['nullable', 'string', 'max:60000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'license_terms' => ['nullable', 'string', 'max:20000'],
            'category_id' => ['nullable','integer',Rule::exists('marketplace_categories','id')],
        ]);

        $this->checkCategory($data);
        BuilderFeaturedMedia::check($data, (int)$request->user()->id);
        BuilderGallery::check($data, $request);
        $listing = DB::transaction(function () use ($data, $request, $termsHash) {
            $listing = MarketplaceListing::query()->create([
                ...$data,
                'user_id' => $request->user()->id,
                'currency' => 'USD',
                'status' => 'pending',
            ]);
            BuilderSubmissionTerms::record($termsHash, (int)$request->user()->id, $listing->type, (int)$listing->id);
            SubmissionNotifications::send($listing);

            return $listing;
        });
        if ($request->boolean('builder_mode')) {
            return redirect($listing->builderUrl())->with('status', 'Listing submitted for review.');
        }

        return redirect()->route('studio.listings')->with('status', 'Listing submitted for review.');
    }

    public function update(Request $request,MarketplaceListing $listing): RedirectResponse
    {
        abort_unless(FeatureFlag::isEnabled('creator_submissions'),403);
        BuilderContent::prepare($request);
        $data=$request->validate([
            'title'=>['required','string','min:4','max:180'],
            'description'=>['required','string','min:40','max:10000'],
            ...BuilderFeaturedMedia::rules(),
            'description_html'=>['nullable','string','max:60000'],
            'price'=>['nullable','numeric','min:0','max:9999999999.99'],
            'license_terms'=>['nullable','string','max:20000'],
            'category_id'=>['nullable','integer',Rule::exists('marketplace_categories','id')],
        ]);
        $termsHash = BuilderSubmissionTerms::requireAcceptance($request);
        DB::transaction(function()use($request,$listing,$data,$termsHash){
            $locked=MarketplaceListing::query()->lockForUpdate()->findOrFail($listing->id);
            abort_unless((int)$locked->user_id===(int)$request->user()->id
                && in_array($locked->type,['project','freelance','service'],true),404);
            abort_unless(in_array($locked->status,['draft','rejected'],true),409);
            $this->checkCategory([...$data,'type'=>$locked->type]);
            BuilderFeaturedMedia::check($data, (int)$request->user()->id);
            BuilderGallery::check($data, $request);
            $locked->update([...$data,'status'=>'pending']);
            BuilderSubmissionTerms::record($termsHash, (int)$request->user()->id, $locked->type, (int)$locked->id);
            AuditEvent::record('listing.resubmitted',$request->user(),$request);
            SubmissionNotifications::send($locked);
        });
        return back()->with('status','Listing changes resubmitted for review.');
    }

    public function changeStatus(Request $request, MarketplaceListing $listing): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['archived', 'draft'])]]);
        $status = $data['status'];
        $this->changeOwnedStatus($request, $listing, $status);

        return $status === 'archived'
            ? redirect()->route('studio.listings', ['status' => 'archived'])->with('status', 'Listing archived.')
            : redirect($listing->builderUrl())->with('status', 'Listing moved to draft.');
    }

    public function destroy(Request $request, MarketplaceListing $listing): RedirectResponse
    {
        $this->changeOwnedStatus($request, $listing, 'deleted');

        $filters = $request->input('from') === 'archived' ? ['status' => 'archived'] : [];

        return redirect()->route('studio.listings', $filters)->with('status', 'Listing deleted.');
    }

    private function changeOwnedStatus(Request $request, MarketplaceListing $listing, string $status): void
    {
        DB::transaction(function () use ($request, $listing, $status): void {
            $locked = MarketplaceListing::query()->whereKey($listing->id)
                ->where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === $status) {
                return;
            }

            abort_unless($locked->canCreatorSetStatus($status), $locked->status === 'deleted' ? 404 : 409);

            if ($locked->type === 'product' && $this->hasCustomerAccess($locked)) {
                throw ValidationException::withMessages([
                    'listing' => 'This product has an existing customer license or download entitlement. Contact the Support Center to manage it.',
                ]);
            }

            // Preserve references from contracts, inquiries, and other historical records.
            $locked->update(['status' => $status]);
            AuditEvent::record('listing.creator.'.$status, $request->user(), $request);
            if ($status === 'archived') {
                EmailOutbox::queue('listing.archived', $locked->user_id, (string) Str::uuid(), [
                    'title' => $locked->title,
                ], route('studio.listings', ['status' => 'archived'], false));
            }
        }, 3);
    }

    private function hasCustomerAccess(MarketplaceListing $listing): bool
    {
        return ProductLicense::query()->where('marketplace_listing_id', $listing->id)
            ->whereIn('status', ['active', 'suspended'])->whereNull('revoked_at')
            ->whereHas('entitlement', fn ($query) => $query->where('status', 'active')->whereNull('revoked_at'))
            ->exists()
            || ProductEntitlement::query()->where('status', 'active')->whereNull('revoked_at')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->whereHas('version', fn ($query) => $query->where('marketplace_listing_id', $listing->id))->exists();
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

