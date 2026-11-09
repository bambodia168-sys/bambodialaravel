<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserOwnershipTransferController extends Controller
{
    private const LISTING_TYPES=['product','project','service','freelance'];

    public function create(Request $request,User $user): View
    {
        $data=$request->validate([
            'type'=>['required',Rule::in([...self::LISTING_TYPES,'job'])],
            'id'=>['required','integer','min:1'],
        ]);
        $item=$this->ownedItem($user,$data['type'],(int)$data['id']);
        return view('dashboard.user-transfer',[
            'member'=>$user,'item'=>$item,'type'=>$data['type'],
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
        $target=User::query()->where('public_id',$data['recipient'])
            ->orWhere('email',$data['recipient'])->first();
        abort_unless($target && $target->account_status==='active',422,'Choose an existing, active recipient.');
        abort_if($target->id===$user->id,422,'The new owner must be a different user.');
        if($data['type']==='job'){
            abort_unless(in_array($target->role,['customer','subscriber','super_admin'],true),422,
                'A job recipient must be a customer, subscriber or super administrator.');
        } else {
            abort_unless(in_array($target->role,['freelancer','super_admin'],true),422,
                'A listing recipient must be a freelancer or super administrator.');
        }

        DB::transaction(function()use($user,$target,$data,$request){
            // Lock all participating accounts before the resource to serialize admin changes.
            User::query()->whereIn('id',[$user->id,$target->id])->orderBy('id')->lockForUpdate()->get();
            abort_unless(User::query()->whereKey($target->id)->where('account_status','active')->exists(),409);
            $item=$this->ownedItem($user,$data['type'],(int)$data['id'],true);
            if($data['type']==='job'){
                abort_if(DB::table('job_proposals')->where('marketplace_job_id',$item->id)->exists(),409,
                    'This job has proposals; transfer requires case review.');
                abort_if(DB::table('freelance_contracts')
                    ->join('job_proposals','job_proposals.id','=','freelance_contracts.job_proposal_id')
                    ->where('job_proposals.marketplace_job_id',$item->id)->exists(),409,
                    'This job has contracts; ownership cannot be silently changed.');
                $this->checkMedia($item,$user->id);
                $item->client_id=$target->id;
            } else {
                abort_if(DB::table('service_inquiries')->where('marketplace_listing_id',$item->id)->exists(),409,
                    'This listing has private inquiries and cannot be transferred automatically.');
                if($data['type']==='product'){
                    abort_if(DB::table('product_file_versions')->where('marketplace_listing_id',$item->id)->exists(),409,
                        'This product has private file releases; manual entitlement review required.');
                    abort_if(DB::table('product_licenses')->where('marketplace_listing_id',$item->id)->exists(),409,
                        'This product has issued licenses and cannot be transferred automatically.');
                }
                $this->checkMedia($item,$user->id);
                $item->user_id=$target->id;
            }
            $item->save();
            DB::table('user_ownership_transfers')->insert([
                'actor_id'=>$request->user()->id,'from_user_id'=>$user->id,
                'to_user_id'=>$target->id,'resource_type'=>$data['type'],
                'resource_id'=>$item->id,'resource_public_id'=>$item->public_id,
                'resource_title'=>$item->title,'reason'=>$data['reason'],'created_at'=>now(),
            ]);
            AuditEvent::record('user.resource.transferred',$request->user(),$request);
        },3);

        return redirect()->route('dashboard.users.show',$user)
            ->with('status','Ownership transfer completed and recorded in the audit trail.');
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

    private function checkMedia(MarketplaceListing|MarketplaceJob $item,int $ownerId): void
    {
        // Reusing another person's media without transferring its rights/storage access
        // can break the new owner's editor and may violate upload permissions.
        // Such cases require a separate, explicit media and rights review.
        $attachments=$item->gallery_media_ids ?: [];
        abort_if(!is_array($attachments) || count($attachments)>0 ||
            $item->featured_media_id !== null ||
            ($item instanceof MarketplaceListing && $item->cover_media_id !== null),409,
            'This item contains media assets. Transfer requires a separate rights/media review.');
    }
}
