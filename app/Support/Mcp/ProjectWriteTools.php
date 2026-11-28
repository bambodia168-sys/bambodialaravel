<?php

namespace App\Support\Mcp;

use App\Models\AccountNotification;
use App\Models\AuditEvent;
use App\Models\FreelanceContract;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\ServiceInquiry;
use App\Models\User;
use App\Support\OrderReference;
use App\Support\UserManagementAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Explicitly confirmed, participant-scoped project mutations. */
final class ProjectWriteTools
{
    public const TOOLS=[
        'bambodia_tracking_task_create'=>'Create a checklist task as the seller of your verified order.',
        'bambodia_tracking_task_update'=>'Update a checklist task status as the seller of your verified order.',
        'bambodia_contract_milestone_create'=>'Append a milestone to your client-owned unsent draft contract.',
        'bambodia_tracking_comment_create'=>'Send a confirmed plain-text message to the other participant; a notification is created.',
    ];
    private const STATUSES=['planned','in_progress','completed'];

    public static function definitions(bool $includeWrites): array
    {
        if (!$includeWrites) return [];
        $ref=['type'=>'string','pattern'=>'^[1-9][0-9]{9}$'];
        $date=['type'=>['string','null'],'format'=>'date'];
        $text=fn(int $min,int $max)=>['type'=>'string','minLength'=>$min,'maxLength'=>$max];
        $out=[];
        foreach (self::TOOLS as $name=>$description) {
            [$props,$required]=match($name) {
                'bambodia_tracking_task_create'=>[['reference'=>$ref,'title'=>$text(5,180),'due_on'=>$date],['reference','title']],
                'bambodia_tracking_task_update'=>[['reference'=>$ref,'task_id'=>['type'=>'integer','minimum'=>1],
                    'status'=>['type'=>'string','enum'=>self::STATUSES]],['reference','task_id','status']],
                'bambodia_contract_milestone_create'=>[['contract_id'=>['type'=>'string','pattern'=>'^[1-9][0-9]{8}$'],
                    'title'=>$text(5,180),'description'=>$text(30,4000),'amount'=>['type'=>'string','pattern'=>'^(0|[1-9][0-9]{0,9})(\\.[0-9]{1,2})?$'],'due_on'=>$date],
                    ['contract_id','title','description','amount']],
                'bambodia_tracking_comment_create'=>[['reference'=>$ref,'message'=>$text(2,4000)],['reference','message']],
            };
            $out[]=['name'=>$name,'description'=>$description.' Use the latest version from tracking_get or milestones_list, a new UUID request_id, and confirm only after reviewing the exact action.',
                'inputSchema'=>['type'=>'object','properties'=>[...$props,
                    'request_id'=>['type'=>'string','format'=>'uuid'],'expected_version'=>['type'=>'string','pattern'=>'^[a-f0-9]{64}$'],
                    'confirm'=>['type'=>'boolean','const'=>true]],'required'=>[...$required,'request_id','expected_version','confirm'],'additionalProperties'=>false],
                'annotations'=>['readOnlyHint'=>false,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>$name==='bambodia_tracking_comment_create']];
        }
        return $out;
    }

    public function call(string $name,array $args,User $owner,bool $mayRead,bool $mayWrite): array
    {
        if (!$mayRead || !$mayWrite || !config('mcp.production_writes_enabled',false))
            $this->reject('access','Project WRITE authorization required.');
        if (!isset(self::TOOLS[$name])) $this->reject('name','Unknown project write tool.');
        $v=$this->validate($name,$args);
        return DB::transaction(function () use ($name,$v,$owner): array {
            // Serialize all MCP requests for this actor, then lock the domain parent.
            $actor=User::query()->whereKey($owner->getKey())->lockForUpdate()->first();
            if (!$actor || $actor->role!=='super_admin' || $actor->account_status!=='active'
                || !config('mcp.production_writes_enabled',false)) $this->reject('access','Active super administrator and WRITE authorization required.');
            $contractMode=$name==='bambodia_contract_milestone_create';
            $reference=$contractMode?$v['contract_id']:$v['reference'];
            $contract=null; $order=null; $inquiry=null;
            if ($contractMode) {
                $contract=FreelanceContract::query()->where('public_id',$reference)->where('client_id',$actor->id)->lockForUpdate()->first();
                if (!$contract) $this->reject('contract_id','Authorized contract not found.');
                $current=ProjectStateVersion::contract((int)$contract->id,true);
            } else {
                if (!UserManagementAccess::allows($actor,'commerce_orders')) $this->reject('access','Commerce tracking is unavailable.');
                [$order,$inquiry]=$this->lockedTracking($reference,$actor,$name!=='bambodia_tracking_comment_create');
                $current=ProjectStateVersion::tracking((int)$order->id,(int)$inquiry->id,true);
            }
            $canonical=$v; ksort($canonical);
            $digest=ProjectStateVersion::digest('request-v1',[$name,$canonical]);
            $prior=DB::table('mcp_project_operations')->where('actor_id',$actor->id)->where('request_id',$v['request_id'])->lockForUpdate()->first();
            if ($prior) {
                if ($prior->tool!==$name || $prior->resource_reference!==$reference || !hash_equals($prior->payload_digest,$digest))
                    $this->reject('request_id','Request ID already used with different arguments.');
                return [...json_decode($prior->result,true,512,JSON_THROW_ON_ERROR),'replayed'=>true];
            }
            if (!hash_equals($current,$v['expected_version'])) $this->reject('expected_version','Project state changed. Read the latest version and review again.');
            if ($contractMode) {
                $data=$this->addMilestone($contract,$v);
                $event='contract.milestone.created';
                $version=ProjectStateVersion::contract((int)$contract->id,true);
            } else {
                if ($name==='bambodia_tracking_comment_create') {
                    $data=$this->comment($order,$inquiry,$actor,$v);
                    $event='inquiry.replied';
                } else {
                    if (in_array($inquiry->deliveryStatus(),['delivered','accepted'],true))
                        $this->reject('reference','Tasks cannot change during review or after acceptance.');
                    if ($name==='bambodia_tracking_task_create') {
                        $data=$this->addTask($order,$actor,$v);
                        $event='order.tracking.task_added';
                    } else {
                        $data=$this->updateTask($order,$v);
                        $event='order.tracking.task_updated';
                    }
                    $this->notify($order,$actor,$event,'Project checklist updated','The creator updated project checklist progress.');
                }
                $version=ProjectStateVersion::tracking((int)$order->id,(int)$inquiry->id,true);
            }
            // Native audit event plus a linked MCP-specific audit record, no body or raw payload.
            AuditEvent::record($event,$actor);
            $audit=AuditEvent::query()->create(['actor_id'=>$actor->id,'event'=>'mcp.phase4.'.substr($name,9),'created_at'=>now()]);
            $result=['reference'=>$reference,'request_id'=>$v['request_id'],'audit_id'=>(int)$audit->id,
                ...$data,'version'=>$version];
            DB::table('mcp_project_operations')->insert(['actor_id'=>$actor->id,'request_id'=>$v['request_id'],'tool'=>$name,
                'resource_reference'=>$reference,'payload_digest'=>$digest,'audit_event_id'=>$audit->id,
                'result'=>json_encode($result,JSON_THROW_ON_ERROR),'created_at'=>now()]);
            return [...$result,'replayed'=>false];
        },3);
    }

    private function reject(string $field,string $message): never
    {
        throw ValidationException::withMessages([$field=>$message]);
    }

    private function validate(string $name,array $args): array
    {
        $ref=['required','string','regex:/^'.OrderReference::DIGITS.'$/D'];
        $title=['required','string','min:5','max:180'];
        $date=['sometimes','nullable','string','date_format:Y-m-d','after_or_equal:today'];
        $rules=match($name) {
            'bambodia_tracking_task_create'=>['reference'=>$ref,'title'=>$title,'due_on'=>[...$date,'before_or_equal:'.now()->addYear()->toDateString()]],
            'bambodia_tracking_task_update'=>['reference'=>$ref,'task_id'=>['required','integer','min:1'],'status'=>['required','string','in:'.implode(',',self::STATUSES)]],
            'bambodia_contract_milestone_create'=>['contract_id'=>['required','string','regex:/^[1-9][0-9]{8}$/D'],
                'title'=>$title,'description'=>['required','string','min:30','max:4000'],
                'amount'=>['required','string','regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,2})?$/D'],'due_on'=>$date],
            'bambodia_tracking_comment_create'=>['reference'=>$ref,'message'=>['required','string','min:2','max:4000']],
        };
        $rules=[...$rules,'request_id'=>['required','string','uuid'],'expected_version'=>['required','string','regex:/^[a-f0-9]{64}$/D'],
            'confirm'=>['required','boolean']];
        if (array_diff(array_keys($args),array_keys($rules))) $this->reject('arguments','Unsupported arguments.');
        foreach ($args as $key=>$value) {
            if ($value===null && in_array('nullable',$rules[$key],true)) continue;
            if ((in_array('integer',$rules[$key],true)&&!is_int($value)) || (in_array('string',$rules[$key],true)&&!is_string($value))
                || ($key==='confirm'&&$value!==true)) $this->reject($key,'Invalid JSON scalar type or missing explicit confirmation.');
            if (in_array($key,['title','description','message'],true) && is_string($value)) {
                if (preg_match('/[<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u',$value)
                    || ($key==='message' && preg_match('~(?:https?://|www\.)~i',$value)))
                    $this->reject($key,'Only plain text is supported; links and embeds are not supported in MCP comments.');
                $args[$key]=trim($value);
            }
        }
        $v=Validator::make($args,$rules)->validate();
        $v['request_id']=strtolower($v['request_id']);
        if (in_array($name,['bambodia_tracking_task_create','bambodia_contract_milestone_create'],true)) $v['due_on']=$v['due_on']??null;
        return $v;
    }

    private function lockedTracking(string $reference,User $actor,bool $sellerOnly): array
    {
        // Native verified-order predicate, locked parent and inquiry, explicit live-mode in QA.
        $order=MarketplaceOrder::query()->where('public_reference',$reference)
            ->where(fn($q)=>$sellerOnly?$q->where('seller_id',$actor->id):$q->where('seller_id',$actor->id)->orWhere('buyer_id',$actor->id))
            ->where('status','completed')->lockForUpdate()->first();
        if (!$order) $this->reject('reference','Authorized verified project not found.');
        $listing=MarketplaceListing::query()->whereKey($order->marketplace_listing_id)->lockForUpdate()->first(['id','type']);
        $inquiry=ServiceInquiry::query()->where('marketplace_order_id',$order->id)->lockForUpdate()->first();
        $paid=$order->paidAttempt()->where('live_mode',true)->lockForUpdate()->first(['id']);
        if (!$listing || !in_array($listing->type,['service','project','freelance'],true) || !$paid || !$inquiry
            || (int)$inquiry->client_id!==(int)$order->buyer_id || (int)$inquiry->creator_id!==(int)$order->seller_id
            || (int)$inquiry->marketplace_listing_id!==(int)$order->marketplace_listing_id)
            $this->reject('reference','Authorized verified project not found.');
        return [$order,$inquiry];
    }

    private function addTask(MarketplaceOrder $order,User $actor,array $v): array
    {
        $tasks=DB::table('order_tracking_tasks')->where('marketplace_order_id',$order->id)->lockForUpdate()->get(['position']);
        $last=(int)$tasks->max('position');
        if ($last>=20 || $tasks->count()>=20) $this->reject('reference','Maximum twenty tasks.');
        $id=DB::table('order_tracking_tasks')->insertGetId(['marketplace_order_id'=>$order->id,'created_by'=>$actor->id,
            'title'=>$v['title'],'due_on'=>$v['due_on'],'position'=>$last+1,'status'=>'planned','created_at'=>now(),'updated_at'=>now()]);
        return ['task_id'=>(int)$id,'status'=>'planned'];
    }

    private function updateTask(MarketplaceOrder $order,array $v): array
    {
        $query=DB::table('order_tracking_tasks')->where('marketplace_order_id',$order->id)->where('id',$v['task_id']);
        $task=(clone $query)->lockForUpdate()->first();
        if (!$task) $this->reject('task_id','Authorized task not found.');
        $query->update(['status'=>$v['status'],'completed_at'=>$v['status']==='completed'?now():null,'updated_at'=>now()]);
        return ['task_id'=>(int)$task->id,'status'=>$v['status']];
    }

    private function cents(string $amount): int
    {
        [$whole,$fraction]=array_pad(explode('.',$amount,2),2,'');
        return (int)$whole*100+(int)str_pad($fraction,2,'0');
    }

    private function addMilestone(FreelanceContract $contract,array $v): array
    {
        if ($contract->status!=='draft') $this->reject('contract_id','Milestones are frozen after offer.');
        $milestones=$contract->milestones()->lockForUpdate()->get(['id','sequence','amount']);
        $sequence=(int)$milestones->max('sequence')+1;
        if ($sequence>20 || $milestones->count()>=20) $this->reject('contract_id','Maximum twenty milestones.');
        $allocated=$milestones->sum(fn($m)=>$this->cents((string)$m->amount));
        if ($allocated+$this->cents($v['amount'])>$this->cents((string)$contract->agreed_budget))
            $this->reject('amount','Milestone allocation exceeds the draft contract budget.');
        $milestone=$contract->milestones()->create(['sequence'=>$sequence,'title'=>$v['title'],'description'=>$v['description'],
            'amount'=>$v['amount'],'due_on'=>$v['due_on'],'status'=>'planned']);
        return ['milestone_id'=>(int)$milestone->id,'sequence'=>$sequence,'status'=>'planned'];
    }

    private function comment(MarketplaceOrder $order,ServiceInquiry $inquiry,User $actor,array $v): array
    {
        if ($inquiry->status!=='open') $this->reject('reference','This conversation is no longer available.');
        // Native plain-message rendering escapes the body; no attachment or embed path.
        $message=$inquiry->messages()->create(['sender_id'=>$actor->id,'body'=>$v['message'],'content_format'=>'plain']);
        $inquiry->touch();
        $this->notify($order,$actor,'inquiry.replied','New project tracking message','You have a new private message about an active order.');
        return ['message_id'=>(int)$message->id,'status'=>'sent'];
    }

    private function notify(MarketplaceOrder $order,User $actor,string $event,string $title,string $body): void
    {
        $recipient=(int)$order->buyer_id===(int)$actor->id?(int)$order->seller_id:(int)$order->buyer_id;
        AccountNotification::deliver($recipient,$event,$title,$body,route('tracking.orders.show',$order->reference(),false));
    }
}
