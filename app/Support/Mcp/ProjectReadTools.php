<?php

namespace App\Support\Mcp;

use App\Models\FreelanceContract;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\ServiceInquiry;
use App\Models\User;
use App\Support\OrderReference;
use App\Support\UserManagementAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Read-only project metadata; no access to conversation, evidence or contract text. */
final class ProjectReadTools
{
    public const TOOLS = [
        'bambodia_tracking_list'=>'List your verified live paid-order projects.',
        'bambodia_tracking_get'=>'Inspect your verified paid-order project and checklist by public order reference.',
        'bambodia_contracts_list'=>'List your participant-owned freelance contracts; unsent drafts are client-only.',
        'bambodia_milestones_list'=>'List safe milestone metadata for a contract you may access.',
    ];
    private const TYPES=['service','project','freelance'];
    private const CONTRACT_STATUSES=['draft','offered','active','disputed','completed','declined','cancelled'];

    public static function definitions(): array
    {
        $page=['type'=>'integer','minimum'=>1,'maximum'=>1000,'default'=>1];
        $direction=['type'=>'string','enum'=>['both','buyer','seller'],'default'=>'both'];
        $role=['type'=>'string','enum'=>['both','client','freelancer'],'default'=>'both'];
        $out=[];
        foreach (self::TOOLS as $name=>$description) {
            [$properties,$required]=match($name) {
                'bambodia_tracking_list'=>[['page'=>$page,'direction'=>$direction,'type'=>['type'=>'string','enum'=>self::TYPES],
                    'status'=>['type'=>'string','enum'=>array_keys(ServiceInquiry::DELIVERY_STATUSES)]],[]],
                'bambodia_tracking_get'=>[['reference'=>['type'=>'string','pattern'=>'^[1-9][0-9]{9}$']],['reference']],
                'bambodia_contracts_list'=>[['page'=>$page,'role'=>$role,'status'=>['type'=>'string','enum'=>self::CONTRACT_STATUSES]],[]],
                'bambodia_milestones_list'=>[['contract_id'=>['type'=>'string','pattern'=>'^[1-9][0-9]{8}$'],'page'=>$page],['contract_id']],
            };
            $out[]=['name'=>$name,'description'=>$description,
                'inputSchema'=>['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>false],
                'annotations'=>['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>false]];
        }
        return $out;
    }

    public function call(string $name,array $args,User $owner,bool $mayRead): array
    {
        $actor=User::query()->find($owner->getKey());
        if (!$mayRead || !$actor || $actor->role!=='super_admin' || $actor->account_status!=='active')
            $this->reject('access','Active super administrator and project read authorization required.');
        if (!isset(self::TOOLS[$name])) $this->reject('name','Unknown project read tool.');
        // Native formal contracts are independent of marketplace commerce.
        if (in_array($name,['bambodia_tracking_list','bambodia_tracking_get'],true)
            && !UserManagementAccess::allows($actor,'commerce_orders'))
            $this->reject('access','Commerce tracking is unavailable.');
        $page=['sometimes','integer','min:1','max:1000'];
        $rules=match($name) {
            'bambodia_tracking_list'=>['page'=>$page,'direction'=>['sometimes','string','in:both,buyer,seller'],
                'type'=>['sometimes','string','in:'.implode(',',self::TYPES)],
                'status'=>['sometimes','string','in:'.implode(',',array_keys(ServiceInquiry::DELIVERY_STATUSES))]],
            'bambodia_tracking_get'=>['reference'=>['required','string','regex:/^'.OrderReference::DIGITS.'$/D']],
            'bambodia_contracts_list'=>['page'=>$page,'role'=>['sometimes','string','in:both,client,freelancer'],
                'status'=>['sometimes','string','in:'.implode(',',self::CONTRACT_STATUSES)]],
            'bambodia_milestones_list'=>['contract_id'=>['required','string','regex:/^[1-9][0-9]{8}$/D'],'page'=>$page],
        };
        if (array_diff(array_keys($args),array_keys($rules))) $this->reject('arguments','Unsupported arguments.');
        foreach ($args as $key=>$value) {
            if ((in_array('integer',$rules[$key],true)&&!is_int($value))
                || (in_array('string',$rules[$key],true)&&!is_string($value)))
                $this->reject($key,'Invalid JSON scalar type.');
        }
        $v=Validator::make($args,$rules)->validate();
        return match($name) {
            'bambodia_tracking_list'=>$this->trackingList($actor,$v),
            'bambodia_tracking_get'=>$this->trackingGet($actor,$v['reference']),
            'bambodia_contracts_list'=>$this->contractList($actor,$v),
            'bambodia_milestones_list'=>$this->milestones($actor,$v),
        };
    }

    private function reject(string $field,string $message): never
    {
        throw ValidationException::withMessages([$field=>$message]);
    }

    // Same participant, listing and inquiry consistency rules as VerifiedTrackingOrder;
    // explicitly require live_mode even in isolated QA, as in CommerceReadTools.
    private function tracking(User $actor,array $v=[]): Builder
    {
        $direction=$v['direction']??'both';
        $status=$v['status']??null;
        $type=$v['type']??null;
        return MarketplaceOrder::query()
            ->select(['id','public_reference','buyer_id','seller_id','marketplace_listing_id','listing_title','completed_at'])
            ->addSelect(['listing_type'=>MarketplaceListing::query()->select('type')->whereColumn('id','marketplace_orders.marketplace_listing_id')->limit(1)])
            ->where(function (Builder $q) use ($actor,$direction): void {
                if ($direction==='buyer') $q->where('buyer_id',$actor->id);
                elseif ($direction==='seller') $q->where('seller_id',$actor->id);
                else $q->where('buyer_id',$actor->id)->orWhere('seller_id',$actor->id);
            })
            ->where('status','completed')->whereHas('paidAttempt',fn (Builder $q)=>$q->where('live_mode',true))
            ->whereIn('marketplace_listing_id',MarketplaceListing::query()->select('id')->whereIn('type',self::TYPES)
                ->when($type!==null,fn (Builder $q)=>$q->where('type',$type)))
            ->whereHas('serviceInquiry',function (Builder $q) use ($status): void {
                $q->whereColumn('client_id','marketplace_orders.buyer_id')
                    ->whereColumn('creator_id','marketplace_orders.seller_id')
                    ->whereColumn('marketplace_listing_id','marketplace_orders.marketplace_listing_id');
                if ($status==='awaiting_requirements')
                    $q->where(fn (Builder $s)=>$s->whereNull('delivery_status')->orWhere('delivery_status',$status));
                elseif ($status!==null) $q->where('delivery_status',$status);
            })
            ->with('serviceInquiry:id,marketplace_order_id,marketplace_listing_id,client_id,creator_id,delivery_status');
    }

    private function trackingData(MarketplaceOrder $order,User $actor): array
    {
        $inquiry=$order->serviceInquiry;
        // Fail closed if ownership links change between query and relationship load.
        if (!$inquiry || (int)$inquiry->client_id!==(int)$order->buyer_id
            || (int)$inquiry->creator_id!==(int)$order->seller_id
            || (int)$inquiry->marketplace_listing_id!==(int)$order->marketplace_listing_id)
            $this->reject('reference','Authorized verified project not found.');
        return ['reference'=>(string)$order->public_reference,'listing_title'=>$order->listing_title,
            'listing_type'=>$order->listing_type,'relationship'=>(int)$order->buyer_id===(int)$actor->id?'buyer':'seller',
            'delivery_status'=>$inquiry->deliveryStatus(),'verified_live_payment'=>true,
            'completed_at'=>$order->completed_at?->toIso8601String()];
    }

    private function trackingList(User $actor,array $v): array
    {
        $page=$this->tracking($actor,$v)->orderByDesc('id')->paginate(20,page:(int)($v['page']??1));
        return $this->page($page,fn ($r)=>$this->trackingData($r,$actor));
    }

    private function trackingGet(User $actor,string $reference): array
    {
        $order=$this->tracking($actor)->where('public_reference',$reference)->first();
        if (!$order) $this->reject('reference','Authorized verified project not found.');
        $data=$this->trackingData($order,$actor);
        $query=DB::table('order_tracking_tasks')->where('marketplace_order_id',$order->id);
        $total=(clone $query)->count();
        $completed=(clone $query)->where('status','completed')->count();
        $tasks=$query->orderBy('position')->orderBy('id')->limit(20)
            ->get(['id','position','title','status','due_on','completed_at'])
            ->map(fn ($r)=>['task_id'=>(int)$r->id,'position'=>(int)$r->position,'title'=>$r->title,
                'status'=>$r->status,'due_on'=>$r->due_on,'completed_at'=>$r->completed_at])->all();
        $progress=match($data['delivery_status']) {
            'awaiting_requirements'=>10,
            'in_progress'=>$total?35+(int)round(35*$completed/$total):45,
            'delivered'=>85,'revision_requested'=>65,'accepted'=>100,default=>0,
        };
        $extensions=DB::table('order_tracking_extension_requests')->where('marketplace_order_id',$order->id);
        return [...$data,'task_count'=>$total,'completed_task_count'=>$completed,
            'tasks_truncated'=>$total>20,'tasks'=>$tasks,'delivery_progress_percent'=>$progress,
            'progress_basis'=>'Native delivery-state estimate; checklist completion is not customer acceptance.',
            'approved_due_on'=>(clone $extensions)->where('status','approved')->latest('id')->value('proposed_due_on'),
            'pending_extension'=>(clone $extensions)->where('status','pending')->exists(),
            'version'=>ProjectStateVersion::tracking((int)$order->id,(int)$order->serviceInquiry->id)];
    }

    private function contracts(User $actor,string $role='both'): Builder
    {
        // Native contract visibility: client sees draft; freelancer only after offer.
        return FreelanceContract::query()->select(['id','public_id','client_id','freelancer_id','title','status',
            'currency','agreed_budget','offered_at','accepted_at','completed_at','cancelled_at'])
            ->whereIn('status',self::CONTRACT_STATUSES)
            ->where(function (Builder $q) use ($actor,$role): void {
                if ($role==='client') $q->where('client_id',$actor->id);
                elseif ($role==='freelancer') $q->where('freelancer_id',$actor->id)->where('status','!=','draft');
                else $q->where('client_id',$actor->id)->orWhere(fn (Builder $f)=>$f
                    ->where('freelancer_id',$actor->id)->where('status','!=','draft'));
            })
            ->withCount(['milestones','milestones as accepted_milestones_count'=>fn (Builder $q)=>$q->where('status','accepted')]);
    }

    private function contractData(FreelanceContract $contract,User $actor): array
    {
        return ['contract_id'=>(string)$contract->public_id,'title'=>$contract->title,
            'relationship'=>(int)$contract->client_id===(int)$actor->id?'client':'freelancer',
            'status'=>$contract->status,'currency'=>$contract->currency,'agreed_budget'=>(string)$contract->agreed_budget,
            'milestone_count'=>(int)$contract->milestones_count,'accepted_milestone_count'=>(int)$contract->accepted_milestones_count,
            'offered_at'=>$contract->offered_at?->toIso8601String(),'accepted_at'=>$contract->accepted_at?->toIso8601String(),
            'completed_at'=>$contract->completed_at?->toIso8601String(),'cancelled_at'=>$contract->cancelled_at?->toIso8601String()];
    }

    private function contractList(User $actor,array $v): array
    {
        $page=$this->contracts($actor,$v['role']??'both')->when(isset($v['status']),fn (Builder $q)=>$q->where('status',$v['status']))
            ->orderByDesc('id')->paginate(20,page:(int)($v['page']??1));
        return $this->page($page,fn ($r)=>$this->contractData($r,$actor));
    }

    private function milestones(User $actor,array $v): array
    {
        $contract=$this->contracts($actor)->where('public_id',$v['contract_id'])->first();
        if (!$contract) $this->reject('contract_id','Authorized contract not found.');
        $page=$contract->milestones()->select(['id','freelance_contract_id','sequence','title','amount','due_on','status','accepted_at'])
            ->paginate(20,page:(int)($v['page']??1));
        return ['contract'=>$this->contractData($contract,$actor),
            ...$this->page($page,fn ($r)=>['milestone_id'=>(int)$r->id,'sequence'=>(int)$r->sequence,'title'=>$r->title,
                'amount'=>(string)$r->amount,'due_on'=>$r->due_on?->toDateString(),'status'=>$r->status,
                'accepted_at'=>$r->accepted_at?->toIso8601String()]),
            'amount_basis'=>'Contractual milestone amount, not payment or escrow status.',
            'version'=>ProjectStateVersion::contract((int)$contract->id)];
    }

    private function page($page,callable $map): array
    {
        return ['data'=>array_map($map,$page->items()),'page'=>$page->currentPage(),
            'pages'=>$page->lastPage(),'per_page'=>20,'total'=>$page->total()];
    }
}
