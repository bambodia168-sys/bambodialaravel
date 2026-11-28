<?php

namespace App\Support\Mcp;

use App\Models\MarketplaceOrder;
use App\Models\ProductLicense;
use App\Models\User;
use App\Support\OrderReceipt;
use App\Support\OrderReference;
use App\Support\UserManagementAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Participant-scoped commerce metadata. No gateway calls, fulfillment or writes. */
final class CommerceReadTools
{
    public const TOOLS = [
        'bambodia_orders_list'=>'List orders in which you are the buyer or seller.',
        'bambodia_order_get'=>'Inspect one of your orders by its ten-digit public reference.',
        'bambodia_transactions_summary'=>'Summarize your verified live orders by currency and current status; not revenue or profit.',
        'bambodia_receipt_get'=>'Inspect safe receipt metadata for one of your eligible orders; no verification token.',
        'bambodia_licenses_list'=>'List licenses you hold or issued for products you own; no keys or domains.',
        'bambodia_license_get'=>'Inspect an authorized license by public UUID; no keys or domains.',
    ];

    private const ORDER_COLUMNS = [
        'id','public_reference','buyer_id','seller_id','listing_title','total','currency','status',
        'receipt_number','created_at','completed_at','cancelled_at',
    ];
    private const LICENSE_COLUMNS = [
        'id','public_id','user_id','marketplace_listing_id','product_entitlement_id',
        'product_file_version_id','tier','status','max_domains','revoked_at','support_term','support_expires_at',
    ];

    public static function definitions(): array
    {
        $page=['type'=>'integer','minimum'=>1,'maximum'=>1000,'default'=>1];
        $reference=['type'=>'string','pattern'=>'^[1-9][0-9]{9}$'];
        $uuid=['type'=>'string','format'=>'uuid'];
        $direction=['type'=>'string','enum'=>['both','buyer','seller'],'default'=>'both'];
        $licenseScope=['type'=>'string','enum'=>['both','customer','creator'],'default'=>'both'];
        $out=[];
        foreach (self::TOOLS as $name=>$description) {
            [$properties,$required]=match($name) {
                'bambodia_orders_list'=>[['page'=>$page,'direction'=>$direction,'status'=>['type'=>'string','enum'=>array_keys(MarketplaceOrder::STATUSES)]],[]],
                'bambodia_order_get','bambodia_receipt_get'=>[['reference'=>$reference],['reference']],
                'bambodia_transactions_summary'=>[['days'=>['type'=>'integer','minimum'=>1,'maximum'=>90,'default'=>7],'direction'=>$direction],[]],
                'bambodia_licenses_list'=>[['page'=>$page,'scope'=>$licenseScope],[]],
                'bambodia_license_get'=>[['license_id'=>$uuid],['license_id']],
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
        if (!$mayRead || !$actor || $actor->role!=='super_admin' || $actor->account_status!=='active'
            || !UserManagementAccess::allows($actor,'commerce_orders')) {
            $this->reject('access','Active super administrator and commerce read authorization required.');
        }
        if (!isset(self::TOOLS[$name])) $this->reject('name','Unknown commerce read tool.');
        $page=['sometimes','integer','min:1','max:1000'];
        $ref=['required','string','regex:/^'.OrderReference::DIGITS.'$/D'];
        $direction=['sometimes','string','in:both,buyer,seller'];
        $rules=match($name) {
            'bambodia_orders_list'=>['page'=>$page,'direction'=>$direction,'status'=>['sometimes','string','in:'.implode(',',array_keys(MarketplaceOrder::STATUSES))]],
            'bambodia_order_get','bambodia_receipt_get'=>['reference'=>$ref],
            'bambodia_transactions_summary'=>['days'=>['sometimes','integer','min:1','max:90'],'direction'=>$direction],
            'bambodia_licenses_list'=>['page'=>$page,'scope'=>['sometimes','string','in:both,customer,creator']],
            'bambodia_license_get'=>['license_id'=>['required','string','uuid']],
        };
        if (array_diff(array_keys($args),array_keys($rules))) $this->reject('arguments','Unsupported arguments.');
        foreach ($args as $key=>$value) {
            if ((in_array('integer',$rules[$key],true)&&!is_int($value))
                || (in_array('string',$rules[$key],true)&&!is_string($value))) {
                $this->reject($key,'Invalid JSON scalar type.');
            }
        }
        $v=Validator::make($args,$rules)->validate();
        return match($name) {
            'bambodia_orders_list'=>$this->orderList($actor,$v),
            'bambodia_order_get'=>$this->orderData($this->order($actor,$v['reference']),$actor),
            'bambodia_transactions_summary'=>$this->transactions($actor,$v),
            'bambodia_receipt_get'=>$this->receipt($actor,$v['reference']),
            'bambodia_licenses_list'=>$this->licenseList($actor,$v),
            'bambodia_license_get'=>$this->licenseData($this->license($actor,$v['license_id']),$actor),
        };
    }

    private function reject(string $field,string $message): never
    {
        throw ValidationException::withMessages([$field=>$message]);
    }

    private function orders(User $actor,string $direction='both'): Builder
    {
        return MarketplaceOrder::query()->where(function (Builder $q) use ($actor,$direction): void {
            if ($direction==='buyer') $q->where('buyer_id',$actor->id);
            elseif ($direction==='seller') $q->where('seller_id',$actor->id);
            else $q->where('buyer_id',$actor->id)->orWhere('seller_id',$actor->id);
        });
    }

    // Explicit live-mode constraint even in QA; native paidAttempt also requires a settlement reference.
    private function withVerified(Builder $q): Builder
    {
        return $q->select(self::ORDER_COLUMNS)->withExists(['paidAttempt as paid_attempt_exists'=>fn (Builder $p)=>$p->where('live_mode',true)]);
    }

    private function order(User $actor,string $reference): MarketplaceOrder
    {
        $order=$this->withVerified($this->orders($actor))->where('public_reference',$reference)->first();
        if (!$order) $this->reject('reference','Authorized order not found.');
        return $order;
    }

    private function orderData(MarketplaceOrder $order,User $actor): array
    {
        return [
            'reference'=>(string)$order->public_reference,'listing_title'=>$order->listing_title,
            'relationship'=>(int)$order->buyer_id===(int)$actor->id?'buyer':'seller',
            'total'=>(string)$order->total,'currency'=>$order->currency,'status'=>$order->status,
            'verified_live_payment'=>(bool)$order->paid_attempt_exists,
            'receipt_status'=>OrderReceipt::status($order),
            'created_at'=>$order->created_at?->toIso8601String(),
            'completed_at'=>$order->completed_at?->toIso8601String(),
            'cancelled_at'=>$order->cancelled_at?->toIso8601String(),
        ];
    }

    private function orderList(User $actor,array $v): array
    {
        $q=$this->withVerified($this->orders($actor,$v['direction']??'both'))
            ->when(isset($v['status']),fn (Builder $q)=>$q->where('status',$v['status']));
        $page=$q->orderByDesc('id')->paginate(20,page:(int)($v['page']??1));
        return ['data'=>array_map(fn ($r)=>$this->orderData($r,$actor),$page->items()),
            'page'=>$page->currentPage(),'pages'=>$page->lastPage(),'per_page'=>20,'total'=>$page->total()];
    }

    private function transactions(User $actor,array $v): array
    {
        $days=$v['days']??7;
        $end=now()->toImmutable(); $start=$end->subDays($days);
        $rows=$this->orders($actor,$v['direction']??'both')
            ->whereIn('status',['completed','refunded'])->where('total','>',0)
            ->whereBetween('completed_at',[$start,$end])
            ->whereHas('paidAttempt',fn (Builder $q)=>$q->where('live_mode',true))
            ->selectRaw('currency, status, COUNT(*) AS order_count, SUM(total) AS gross_amount')
            ->groupBy('currency','status')->orderBy('currency')->orderBy('status')->get();
        return ['period_days'=>$days,'from'=>$start->toIso8601String(),'to'=>$end->toIso8601String(),
            'basis'=>'Order completion window; current order status; verified live paid attempt; each order counted once.',
            'amount_note'=>'Gross original order amounts, not net settlement, platform revenue, refund amount or profit.',
            'by_currency_and_status'=>$rows->map(fn ($r)=>['currency'=>$r->currency,'status'=>$r->status,
                'orders'=>(int)$r->order_count,'gross_order_amount'=>(string)$r->gross_amount])->all()];
    }

    private function receipt(User $actor,string $reference): array
    {
        $order=$this->order($actor,$reference);
        $status=OrderReceipt::status($order);
        if (!$status) $this->reject('reference','Authorized eligible receipt not found.');
        return ['reference'=>(string)$order->public_reference,'status'=>$status,'currency'=>$order->currency,
            'total'=>(string)$order->total,'completed_at'=>$order->completed_at?->toIso8601String()];
    }

    private function licenses(User $actor,string $scope='both'): Builder
    {
        return ProductLicense::query()->select(self::LICENSE_COLUMNS)
            ->whereHas('product',fn (Builder $q)=>$q->where('type','product'))
            ->whereHas('entitlement',fn (Builder $q)=>$q
                ->whereColumn('product_entitlements.user_id','product_licenses.user_id')
                ->whereColumn('product_entitlements.product_file_version_id','product_licenses.product_file_version_id'))
            ->whereHas('version',fn (Builder $q)=>$q->whereColumn('product_file_versions.marketplace_listing_id','product_licenses.marketplace_listing_id'))
            ->where(function (Builder $q) use ($actor,$scope): void {
                if ($scope==='customer') $q->where('user_id',$actor->id);
                elseif ($scope==='creator') $q->whereHas('product',fn (Builder $p)=>$p->where('user_id',$actor->id));
                else $q->where('user_id',$actor->id)->orWhereHas('product',fn (Builder $p)=>$p->where('user_id',$actor->id));
            })
            ->with(['entitlement:id,status,revoked_at,expires_at','product:id,public_id,user_id,title'])
            ->withCount(['activations as active_activations_count'=>fn (Builder $q)=>$q->where('status','active')]);
    }

    private function license(User $actor,string $uuid): ProductLicense
    {
        $license=$this->licenses($actor)->where('public_id',$uuid)->first();
        if (!$license) $this->reject('license_id','Authorized license not found.');
        return $license;
    }

    private function licenseData(ProductLicense $license,User $actor): array
    {
        return ['license_id'=>$license->public_id,'product_id'=>$license->product?->public_id,
            'product_title'=>$license->product?->title,
            'relationship'=>(int)$license->user_id===(int)$actor->id?'customer':'creator',
            'tier'=>$license->tier,'status'=>$license->status,'installation_active'=>$license->isActive(),
            'download_entitlement_active'=>$license->entitlement?->isActive()??false,
            'max_domains'=>(int)$license->max_domains,'active_activations'=>(int)$license->active_activations_count,
            'support_status'=>$license->supportStatus(),'support_expires_at'=>$license->support_expires_at?->toIso8601String()];
    }

    private function licenseList(User $actor,array $v): array
    {
        $page=$this->licenses($actor,$v['scope']??'both')->orderByDesc('id')->paginate(20,page:(int)($v['page']??1));
        return ['data'=>array_map(fn ($r)=>$this->licenseData($r,$actor),$page->items()),
            'page'=>$page->currentPage(),'pages'=>$page->lastPage(),'per_page'=>20,'total'=>$page->total()];
    }
}
