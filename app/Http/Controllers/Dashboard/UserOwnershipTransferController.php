<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\MarketplaceManagement;
use App\Support\PreviewContentReview;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class UserOwnershipTransferController extends Controller
{
    private const LISTING_TYPES=['product','project','service','freelance'];

    public function create(Request $request, User $user): View|RedirectResponse
    {
        $fromManager = $request->routeIs('dashboard.marketplace.transfer', 'localized.*.dashboard.marketplace.transfer');
        if (! $request->filled(['type', 'id'])) {
            return redirect($fromManager ? MarketplaceManagement::indexUrl('freelance') : route('dashboard.users.show', $user))
                ->with('warning', 'Choose the listing or job you want to transfer.');
        }

        $data=$request->validate([
            'type'=>['required',Rule::in([...self::LISTING_TYPES,'job'])],
            'id'=>['required','integer','min:1'],
        ]);
        $item=$this->ownedItem($user,$data['type'],(int)$data['id']);
        return view('dashboard.user-transfer',[
            'member'=>$user,'item'=>$item,'type'=>$data['type'],
            'fromManager'=>$fromManager,
        ]);
    }

    public function store(Request $request,User $user): RedirectResponse
    {
        $data=$request->validate([
            'type'=>['required',Rule::in([...self::LISTING_TYPES,'job'])],
            'id'=>['required','integer','min:1'],
            'recipient'=>['required','string','max:255'],
            'reason'=>['required','string','min:20','max:500'],
            'rights_confirmed'=>['accepted'],
            'confirmation'=>['required','in:TRANSFER'],
        ]);
        $type = $data['type'];
        $id = (int) $data['id'];
        $fromManager = $request->routeIs('dashboard.marketplace.transfer.store', 'localized.*.dashboard.marketplace.transfer.store');
        $formUrl = route($fromManager ? 'dashboard.marketplace.transfer' : 'dashboard.users.transfer', [
            'user' => $user, 'type' => $type, 'id' => $id,
        ]);
        $target = User::query()->where('public_id', $data['recipient'])
            ->orWhere('email', $data['recipient'])->first();
        $recipientError = match (true) {
            ! $target || $target->account_status !== 'active' => 'Choose an existing, active recipient.',
            (int) $target->id === (int) $user->id => 'The new owner must be a different user.',
            $type === 'job' && ! in_array($target->role, ['customer', 'subscriber', 'super_admin'], true)
                => 'A job recipient must be a customer, subscriber or super administrator.',
            $type !== 'job' && ! $target->isCreator()
                => 'A listing recipient must be a freelancer or super administrator.',
            default => null,
        };
        if ($recipientError !== null) {
            throw ValidationException::withMessages(['recipient' => $recipientError])->redirectTo($formUrl);
        }

        try {
            $this->transfer($request, $user, $target, $data, $fromManager);
        } catch (HttpExceptionInterface $exception) {
            if ($exception->getStatusCode() !== 409 || $request->expectsJson()) {
                throw $exception;
            }

            return redirect($formUrl)->withInput($request->only('recipient', 'reason'))
                ->withErrors(['transfer' => $exception->getMessage()]);
        }

        $destination = $fromManager ? MarketplaceManagement::indexUrl($type) : route('dashboard.users.show', $user);

        return redirect($destination)
            ->with('status','Ownership transfer completed and recorded in the audit trail.');
    }

    private function transfer(Request $request, User $user, User $target, array $data, bool $fromManager): void
    {
        DB::transaction(function () use ($user, $target, $data, $request, $fromManager): void {
            // Lock all participating accounts before the resource to serialize admin changes.
            User::query()->whereIn('id',[$user->id,$target->id])->orderBy('id')->lockForUpdate()->get();
            $recipient = User::query()->findOrFail($target->id);
            abort_unless($recipient->account_status === 'active' && $recipient->role === $target->role, 409,
                'The recipient account changed. Check the recipient and try again.');
            $item=$this->ownedItem($user,$data['type'],(int)$data['id'],true);
            abort_if(in_array($item->status, ['withdrawn', 'deleted'], true), 409,
                'Withdrawn or deleted records cannot be transferred.');
            if($data['type']==='job'){
                abort_if(DB::table('job_proposals')->where('marketplace_job_id',$item->id)->exists(),409,
                    'This job has proposals; transfer requires case review.');
            } else {
                abort_if(DB::table('service_inquiries')->where('marketplace_listing_id',$item->id)->exists(),409,
                    'This listing has private inquiries and cannot be transferred automatically.');
                if($data['type']==='product'){
                    abort_if(DB::table('product_file_versions')->where('marketplace_listing_id',$item->id)->exists(),409,
                        'This product has private file releases; manual entitlement review required.');
                    abort_if(DB::table('product_licenses')->where('marketplace_listing_id',$item->id)->exists(),409,
                        'This product has issued licenses and cannot be transferred automatically.');
                }
            }
            abort_if($fromManager && PreviewContentReview::hasCustomerActivity($item), 409,
                'This record has customer activity such as saved items, comments, or support history. Review that activity before transferring ownership.');

            $mediaIds = $this->reassignMedia($item, $user->id, $target->id);
            $item->setAttribute($item instanceof MarketplaceJob ? 'client_id' : 'user_id', $target->id);
            $item->save();
            DB::table('user_ownership_transfers')->insert([
                'actor_id'=>$request->user()->id,'from_user_id'=>$user->id,
                'to_user_id'=>$target->id,'resource_type'=>$data['type'],
                'resource_id'=>$item->id,'resource_public_id'=>$item->public_id,
                'resource_title'=>$item->title,'reason'=>$data['reason'],
                'media_asset_ids'=>json_encode($mediaIds,JSON_THROW_ON_ERROR),'created_at'=>now(),
            ]);
            AuditEvent::record('user.resource.transferred',$request->user(),$request);
        },3);
    }

    private function ownedItem(User $user,string $type,int $id,bool $lock=false): MarketplaceListing|MarketplaceJob
    {
        if($type==='job'){
            $query=MarketplaceJob::query()->where('client_id',$user->id)->whereKey($id);
        } else {
            $query=MarketplaceListing::query()->where('user_id',$user->id)
                ->where('type',$type)->whereKey($id);
        }
        return ($lock?$query->lockForUpdate():$query)->firstOrFail();
    }

    private function reassignMedia(MarketplaceListing|MarketplaceJob $item,int $oldOwner,int $newOwner): array
    {
        // Reassign exclusively used, owned media along with the listing/job.
        // Storage objects stay in place: permission checks use media_assets.user_id.
        // Licensed/shared assets need independent copyright and operational review.
        $gallery=$item->gallery_media_ids ?? [];
        abort_unless(is_array($gallery),409,'Invalid media gallery requires review.');
        $ids=array_values(array_unique(array_filter(array_map('intval',[
            ...$gallery,
            $item->featured_media_id,
            $item instanceof MarketplaceListing ? $item->cover_media_id : null,
        ]))));
        if (!$ids) return [];

        $assets=MediaAsset::query()->whereIn('id',$ids)->orderBy('id')->lockForUpdate()->get();
        abort_unless($assets->count()===count($ids),409,'An attached media file is missing.');
        foreach($assets as $asset){
            abort_unless((int)$asset->user_id===$oldOwner && $asset->rights_basis==='owned',409,
                'A shared or licensed media asset requires a separate rights review.');
            $linkedToOtherListing=MarketplaceListing::query()
                ->when($item instanceof MarketplaceListing,fn($q)=>$q->whereKeyNot($item->id))
                ->where(function($q)use($asset){
                    $q->where('cover_media_id',$asset->id)
                      ->orWhere('featured_media_id',$asset->id)
                      ->orWhereJsonContains('gallery_media_ids',$asset->id);
                })->exists();
            $linkedToOtherJob=MarketplaceJob::query()
                ->when($item instanceof MarketplaceJob,fn($q)=>$q->whereKeyNot($item->id))
                ->where(function($q)use($asset){
                    $q->where('featured_media_id',$asset->id)
                      ->orWhereJsonContains('gallery_media_ids',$asset->id);
                })->exists();
            $usedInPosts=DB::table('content_entries')->where('featured_media_id',$asset->id)->exists();
            abort_if($linkedToOtherListing || $linkedToOtherJob || $usedInPosts,409,
                'This media is also used by another record and cannot be reassigned automatically.');
        }
        MediaAsset::query()->whereIn('id',$ids)->update(['user_id'=>$newOwner]);
        return $ids;
    }
}
