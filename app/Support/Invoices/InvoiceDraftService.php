<?php

namespace App\Support\Invoices;

use App\Models\AuditEvent;
use App\Models\MarketplaceInvoice;
use App\Models\MarketplaceInvoiceItem;
use App\Models\MarketplaceOrder;
use App\Models\User;
use App\Support\UserManagementAccess;
use App\Policies\MarketplaceInvoicePolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InvoiceDraftService
{
    public function __construct(private readonly MarketplaceInvoicePolicy $policy) {}

    private function digest(string $purpose,array $data): string
    {
        $key=(string)config('app.key');
        if ($key==='') throw new \RuntimeException('Invoice state signing key unavailable.');
        return hash_hmac('sha256','invoice-'.$purpose.':'.json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$key);
    }

    private function sourceSnapshot(MarketplaceOrder $o): array
    {
        return [(int)$o->id,(string)$o->public_reference,(int)$o->seller_id,(int)$o->buyer_id,
            (string)$o->listing_title,(string)$o->currency,InvoiceMoney::format(InvoiceMoney::cents($o->total))];
    }

    private function sourceVersion(MarketplaceOrder $o,Collection $payments,?MarketplaceInvoice $invoice): string
    {
        return $this->digest('source-v1',[$this->sourceSnapshot($o),$o->status,$o->completed_at?->toIso8601String(),
            $payments->map(fn($p)=>[(int)$p->id,$p->status,(bool)$p->live_mode,$p->settlement_reference])->all(),
            $invoice?->public_id,$invoice?->revision,$invoice?->status]);
    }

    private function version(MarketplaceOrder $o,Collection $payments,MarketplaceInvoice $invoice,Collection $items): string
    {
        return $this->digest('draft-v1',[$this->sourceVersion($o,$payments,$invoice),
            $invoice->snapshot_digest,$invoice->currency,$invoice->subtotal,$invoice->total,$invoice->due_on?->format('Y-m-d'),
            $invoice->invoice_number,$invoice->issued_at?->toIso8601String(),$invoice->voided_at?->toIso8601String(),
            $invoice->discarded_at?->toIso8601String(),$items->map(fn($i)=>[$i->position,$i->description,$i->quantity,$i->unit_amount,$i->line_total])->all()]);
    }

    private function order(string $reference,int $seller): MarketplaceOrder
    {
        abort_unless(preg_match('/^[1-9][0-9]{9}$/D',$reference),404);
        return MarketplaceOrder::query()->where('seller_id',$seller)->where('public_reference',$reference)->firstOrFail();
    }

    // Resolve identifiers with the participant filter before taking locks. Lock
    // both account rows in stable order before the commerce order, then payments
    // and invoice. Native payment review/reconciliation also locks order first.
    private function lockSource(User $actor,MarketplaceOrder $hint): array
    {
        abort_unless(MarketplaceInvoicePolicy::enabled() && $actor->isCreator() && $actor->account_status==='active',404);
        $users=User::query()->whereIn('id',[$actor->id,$hint->buyer_id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $seller=$users->get($actor->id);
        $buyer=$users->get($hint->buyer_id);
        abort_unless($seller && $buyer && $this->policy->studio($seller) && (int)$seller->id!==(int)$buyer->id,404);
        $o=MarketplaceOrder::query()->whereKey($hint->id)->where('seller_id',$seller->id)->lockForUpdate()->firstOrFail();
        abort_unless((int)$o->buyer_id===(int)$buyer->id && $o->public_reference===$hint->public_reference,404);
        $payments=$o->payments()->orderBy('id')->lockForUpdate()->get(['id','status','live_mode','settlement_reference']);
        $invoice=MarketplaceInvoice::query()->where('marketplace_order_id',$o->id)->lockForUpdate()->first();
        $items=$invoice ? $invoice->items()->lockForUpdate()->get() : collect();
        if ($invoice) abort_unless((int)$invoice->issuer_id===(int)$seller->id && (int)$invoice->recipient_id===(int)$buyer->id,404);
        return [$seller,$buyer,$o,$payments,$invoice,$items];
    }

    private function eligible(User $buyer,MarketplaceOrder $o,Collection $payments): bool
    {
        return $buyer->account_status==='active' && $o->status==='awaiting_payment'
            && $o->currency==='USD' && InvoiceMoney::cents($o->total)>0
            && $o->receipt_number===null && $o->completed_at===null
            && ! $payments->contains(fn($p)=>$p->status==='paid');
    }

    private function snapshotMatches(MarketplaceOrder $o,MarketplaceInvoice $i,Collection $items): bool
    {
        $line=$items->first();
        return $items->count()===1 && $line && $line->position===1 && $line->quantity===1
            && $i->currency==='USD' && $o->currency===$i->currency
            && $i->subtotal===$i->total && $i->total===$o->total
            && $line->description===$o->listing_title && $line->unit_amount===$i->total && $line->line_total===$i->total
            && (int)$o->seller_id===(int)$i->issuer_id && (int)$o->buyer_id===(int)$i->recipient_id
            && hash_equals($i->snapshot_digest,$this->digest('snapshot-v1',$this->sourceSnapshot($o)));
    }

    private function privateDraft(MarketplaceInvoice $i): bool
    {
        return $i->status==='draft' && $i->invoice_number===null && $i->issued_at===null
            && $i->voided_at===null && $i->discarded_at===null;
    }

    private function due(?string $date): void
    {
        if ($date===null) return;
        $today=CarbonImmutable::today(config('invoices.timezone'));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)
            || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))
            || $date<$today->toDateString() || $date>$today->addDays(365)->toDateString()) {
            throw ValidationException::withMessages(['due_on'=>'Choose a date from today through 365 days ahead.']);
        }
    }

    private function assertMcpReader(User $actor): void
    {
        abort_unless($actor->role==='super_admin' && $actor->account_status==='active'
            && MarketplaceInvoicePolicy::enabled() && UserManagementAccess::allows($actor,'commerce_orders'),404);
    }

    public function preview(User $actor,string $reference,bool $mcp=false): array
    {
        return DB::transaction(function()use($actor,$reference,$mcp): array {
            [$seller,$buyer,$o,$payments,$invoice]=$this->lockSource($actor,$this->order($reference,(int)$actor->id));
            if ($mcp) $this->assertMcpReader($seller);
            abort_unless($this->eligible($buyer,$o,$payments) && !$invoice,404);
            return ['reference'=>$o->public_reference,'description'=>$o->listing_title,'currency'=>$o->currency,
                'total'=>$o->total,'version'=>$this->sourceVersion($o,$payments,null)];
        },3);
    }

    // Used by native UI now and by the separately authorized future MCP adapter.
    // Caller must supply literal boolean confirmation (HTML transport converts its
    // validated checkbox value before calling). No caller-supplied prices/parties.
    public function mutate(User $actor,string $action,string $resource,string $version,string $requestId,mixed $confirm,?string $dueOn=null): array
    {
        abort_unless(MarketplaceInvoicePolicy::writesEnabled() && $actor->isCreator() && $actor->account_status==='active',404);
        if (!in_array($action,['create','update','discard'],true) || $confirm!==true
            || !preg_match('/^[a-f0-9]{64}$/D',$version) || !Str::isUuid($requestId)
            || ($action==='discard' && $dueOn!==null)) {
            throw ValidationException::withMessages(['request'=>'Invalid confirmed invoice operation.']);
        }
        $requestId=strtolower($requestId);
        return DB::transaction(function()use($actor,$action,$resource,$version,$requestId,$dueOn): array {
            if ($action==='create') {
                $hint=$this->order($resource,(int)$actor->id);
            } else {
                abort_unless(Str::isUuid($resource),404);
                $hintInvoice=$this->policy->scoped($actor,true)->where('public_id',$resource)->firstOrFail();
                $hint=$hintInvoice->order()->firstOrFail();
            }
            [$seller,$buyer,$o,$payments,$invoice,$items]=$this->lockSource($actor,$hint);
            abort_unless($this->policy->create($seller,$o) && ($action==='create' || ($invoice && $invoice->public_id===$resource && $this->policy->update($seller,$invoice))),404);
            $tool='invoice.draft.'.$action;
            $digest=$this->digest('request-v1',[$tool,$resource,$version,$dueOn]);
            $prior=DB::table('mcp_invoice_operations')->where('actor_id',$seller->id)->where('request_id',$requestId)->lockForUpdate()->first();
            if ($prior) {
                abort_unless($prior->tool===$tool && $invoice && $prior->resource_reference===$invoice->public_id
                    && hash_equals($prior->payload_digest,$digest),409,'Request ID already used with different arguments.');
                $result=json_decode($prior->result,true,512,JSON_THROW_ON_ERROR);
                ksort($result);
                return [...$result,'replayed'=>true];
            }
            if ($action==='create') {
                abort_unless($this->eligible($buyer,$o,$payments),404);
                abort_if($invoice,409,'An invoice record already exists for this order.');
                abort_unless(hash_equals($this->sourceVersion($o,$payments,null),$version),409,'Source changed. Review it again.');
                $this->due($dueOn);
                $invoice=new MarketplaceInvoice();
                $invoice->forceFill(['public_id'=>(string)Str::uuid(),'marketplace_order_id'=>$o->id,
                    'issuer_id'=>$seller->id,'recipient_id'=>$buyer->id,'currency'=>$o->currency,'subtotal'=>$o->total,
                    'total'=>$o->total,'status'=>'draft','due_on'=>$dueOn,'revision'=>1,
                    'snapshot_digest'=>$this->digest('snapshot-v1',$this->sourceSnapshot($o))])->save();
                $line=new MarketplaceInvoiceItem();
                $line->forceFill(['marketplace_invoice_id'=>$invoice->id,'position'=>1,'description'=>$o->listing_title,
                    'quantity'=>1,'unit_amount'=>$o->total,'line_total'=>$o->total])->save();
                $items=$invoice->items()->get();
            } else {
                abort_unless($this->privateDraft($invoice),409,'Invoice is not a private draft.');
                abort_unless(hash_equals($this->version($o,$payments,$invoice,$items),$version),409,'Invoice changed. Review it again.');
                if ($action==='update') {
                    abort_unless($this->eligible($buyer,$o,$payments) && $this->snapshotMatches($o,$invoice,$items),409,'Source no longer eligible.');
                    $this->due($dueOn);
                    abort_if($invoice->due_on?->format('Y-m-d')===$dueOn,409,'Due date is unchanged.');
                    $invoice->due_on=$dueOn;
                } else {
                    $invoice->status='discarded';
                    $invoice->discarded_at=now();
                }
                $invoice->revision++;
                $invoice->save();
            }
            // Use the native allowlist; serialize actor via locked user row.
            // AuditEvent::record returns void. Select this service's just-created
            // event under the same actor lock, then link it to the ledger.
            AuditEvent::record($tool,$seller);
            $audit=AuditEvent::query()->where('actor_id',$seller->id)->where('event',$tool)->latest('id')->firstOrFail();
            $invoice->refresh();
            $result=['invoice_id'=>$invoice->public_id,'status'=>$invoice->status,'revision'=>$invoice->revision,
                'version'=>$this->version($o,$payments,$invoice,$items),'request_id'=>$requestId,'audit_id'=>(int)$audit->id];
            ksort($result);
            DB::table('mcp_invoice_operations')->insert(['actor_id'=>$seller->id,'request_id'=>$requestId,'tool'=>$tool,
                'resource_reference'=>$invoice->public_id,'payload_digest'=>$digest,'audit_event_id'=>$audit->id,
                'result'=>json_encode($result,JSON_THROW_ON_ERROR),'created_at'=>now()]);
            return [...$result,'replayed'=>false];
        },3);
    }

    public function list(User $actor,bool $studio,?string $status=null): mixed
    {
        $actor=User::query()->whereKey($actor->id)->firstOrFail();
        return $this->policy->scoped($actor,$studio)->when($status,fn($q)=>$q->where('status',$status))
            ->with('order:id,public_reference')->latest('id')->paginate(20)->withQueryString();
    }

    public function detail(User $actor,bool $studio,string $id,bool $mcp=false): array
    {
        abort_unless(Str::isUuid($id),404);
        $hint=$this->policy->scoped($actor,$studio)->where('public_id',$id)->firstOrFail();
        if ($studio) {
            return DB::transaction(function()use($actor,$hint,$mcp): array {
                [$seller,$buyer,$o,$payments,$i,$items]=$this->lockSource($actor,$hint->order()->firstOrFail());
                abort_unless($i && $i->public_id===$hint->public_id && $this->policy->view($seller,$i),404);
                if ($mcp) $this->assertMcpReader($seller);
                $data=$this->detailData($i,$o,$payments,$items,
                    ($mcp || MarketplaceInvoicePolicy::writesEnabled()) && $this->privateDraft($i),
                    $this->eligible($buyer,$o,$payments));
                return $mcp ? $this->mcpMetadata($i,$data,true) : $data;
            },3);
        }
        // Recipient has no draft visibility. Lock parent order for a coherent
        // snapshot; do not expose actor/version tokens to recipients.
        return DB::transaction(function()use($actor,$hint,$mcp): array {
            $actor=User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->policy->viewAny($actor),404);
            if ($mcp) $this->assertMcpReader($actor);
            $o=MarketplaceOrder::query()->whereKey($hint->marketplace_order_id)->where('buyer_id',$actor->id)->lockForUpdate()->firstOrFail();
            $payments=$o->payments()->orderBy('id')->lockForUpdate()->get(['id','status','live_mode','settlement_reference']);
            $i=$this->policy->scoped($actor,false)->whereKey($hint->id)->lockForUpdate()->firstOrFail();
            $data=$this->detailData($i,$o,$payments,$i->items()->lockForUpdate()->get(),false,false);
            return $mcp ? $this->mcpMetadata($i,$data,false) : $data;
        },3);
    }

    private function mcpMetadata(MarketplaceInvoice $i,array $data,bool $issuer): array
    {
        return [...$data,'relationship'=>$issuer?'issuer':'recipient',
            'created_at'=>$i->created_at?->toIso8601String(),'issued_at'=>$i->issued_at?->toIso8601String(),
            'voided_at'=>$i->voided_at?->toIso8601String(),'discarded_at'=>$i->discarded_at?->toIso8601String(),
            'revision'=>$data['can_update']?$i->revision:null,'version'=>$data['can_update']?$data['version']:null];
    }

    private function detailData(MarketplaceInvoice $i,MarketplaceOrder $o,Collection $payments,Collection $items,bool $draftActions,bool $eligible): array
    {
        $matches=$this->snapshotMatches($o,$i,$items);
        $paid=$payments->filter(fn($p)=>$p->status==='paid' && $p->live_mode && filled($p->settlement_reference));
        $anyPaid=$payments->contains(fn($p)=>$p->status==='paid');
        $state='review_required';
        if ($matches) {
            if ($o->status==='awaiting_payment' && !$anyPaid && !$o->receipt_number && !$o->completed_at) $state='unpaid';
            elseif ($o->status==='cancelled' && !$anyPaid) $state='cancelled';
            elseif ($paid->count()===1 && $payments->where('status','paid')->count()===1 && $o->receipt_number && $o->completed_at) {
                if ($o->status==='completed') $state='paid';
                elseif ($o->status==='refunded' && $o->operations()->where('commerce_payment_id',$paid->first()->id)
                    ->where('refund_status','refunded')->whereNotNull('refund_reference')->whereNotNull('refund_resolved_at')->exists()) $state='refunded';
            }
        }
        $overdue=$i->status==='issued' && $state==='unpaid' && $i->due_on
            && $i->due_on->format('Y-m-d')<CarbonImmutable::today(config('invoices.timezone'))->toDateString();
        return ['id'=>$i->public_id,'number'=>$i->invoice_number,'status'=>$i->status,'reference'=>$o->public_reference,
            'currency'=>$i->currency,'total'=>$i->total,'due_on'=>$i->due_on?->format('Y-m-d'),
            'items'=>$items->map(fn($l)=>['description'=>$l->description,'quantity'=>$l->quantity,'amount'=>$l->line_total])->all(),
            'payment_state'=>$state,'overdue'=>(bool)$overdue,'snapshot_valid'=>$matches,'can_update'=>$draftActions && $eligible && $matches,
            'can_discard'=>$draftActions,'version'=>$draftActions?$this->version($o,$payments,$i,$items):null];
    }
}
