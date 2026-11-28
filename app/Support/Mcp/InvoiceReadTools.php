<?php

namespace App\Support\Mcp;

use App\Models\MarketplaceInvoice;
use App\Models\User;
use App\Policies\MarketplaceInvoicePolicy;
use App\Support\Invoices\InvoiceDraftService;
use App\Support\Invoices\InvoiceMoney;
use App\Support\UserManagementAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Participant-only invoice inspection. No issuance, collection or mutation. */
final class InvoiceReadTools
{
    public const TOOLS=[
        'bambodia_invoices_list'=>'List your visible invoices; private drafts and discarded documents are issuer-only.',
        'bambodia_invoice_get'=>'Inspect one authorized invoice, with exact amounts and derived source payment state.',
        'bambodia_invoices_summary'=>'Summarize visible invoice documents created in a bounded window; not revenue, settlement or all-time receivables.',
        'bambodia_invoice_source_preview'=>'Inspect your eligible unpaid order snapshot; no reservation, draft creation or payment.',
    ];
    private const UUID='^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';

    public function __construct(private readonly InvoiceDraftService $native,private readonly MarketplaceInvoicePolicy $policy) {}

    public static function definitions(): array
    {
        if (!MarketplaceInvoicePolicy::enabled()) return [];
        $direction=['type'=>'string','enum'=>['issuer','recipient','both'],'default'=>'both'];
        $out=[];
        foreach(self::TOOLS as $name=>$description) {
            [$properties,$required]=match($name) {
                'bambodia_invoices_list'=>[['page'=>['type'=>'integer','minimum'=>1,'maximum'=>1000,'default'=>1],
                    'direction'=>$direction,'status'=>['type'=>'string','enum'=>MarketplaceInvoice::STATUSES]],[]],
                'bambodia_invoice_get'=>[['invoice_id'=>['type'=>'string','format'=>'uuid','pattern'=>self::UUID]],['invoice_id']],
                'bambodia_invoices_summary'=>[['direction'=>$direction,'days'=>['type'=>'integer','minimum'=>1,'maximum'=>90,'default'=>30]],[]],
                'bambodia_invoice_source_preview'=>[['order_reference'=>['type'=>'string','pattern'=>'^[1-9][0-9]{9}$']],['order_reference']],
            };
            $out[]=['name'=>$name,'description'=>$description,'inputSchema'=>['type'=>'object','properties'=>$properties,
                'required'=>$required,'additionalProperties'=>false],'annotations'=>['readOnlyHint'=>true,'destructiveHint'=>false,
                'idempotentHint'=>true,'openWorldHint'=>false]];
        }
        return $out;
    }

    private function reject(string $field,string $message): never
    {
        throw ValidationException::withMessages([$field=>$message]);
    }

    private function actor(User $owner,bool $mayRead): User
    {
        $actor=User::query()->find($owner->getKey());
        if (!$mayRead || !$actor || $actor->role!=='super_admin' || $actor->account_status!=='active'
            || !MarketplaceInvoicePolicy::enabled() || !UserManagementAccess::allows($actor,'commerce_orders'))
            $this->reject('access','Active super administrator and invoice read authorization required.');
        return $actor;
    }

    public function call(string $name,array $args,User $owner,bool $mayRead): array
    {
        $actor=$this->actor($owner,$mayRead);
        if (!isset(self::TOOLS[$name])) $this->reject('name','Unknown invoice read tool.');
        $direction=['sometimes','string','in:issuer,recipient,both'];
        $rules=match($name) {
            'bambodia_invoices_list'=>['page'=>['sometimes','integer','min:1','max:1000'],'direction'=>$direction,
                'status'=>['sometimes','string','in:'.implode(',',MarketplaceInvoice::STATUSES)]],
            'bambodia_invoice_get'=>['invoice_id'=>['required','string','uuid','regex:/'.self::UUID.'/D']],
            'bambodia_invoices_summary'=>['direction'=>$direction,'days'=>['sometimes','integer','min:1','max:90']],
            'bambodia_invoice_source_preview'=>['order_reference'=>['required','string','regex:/^[1-9][0-9]{9}$/D']],
        };
        if (array_diff(array_keys($args),array_keys($rules))) $this->reject('arguments','Unsupported arguments.');
        foreach($args as $key=>$value) {
            if ((in_array('integer',$rules[$key],true)&&!is_int($value)) ||
                (in_array('string',$rules[$key],true)&&!is_string($value)))
                $this->reject($key,'Invalid JSON scalar type.');
        }
        $v=Validator::make($args,$rules)->validate();
        try {
            $result=match($name) {
                'bambodia_invoices_list'=>$this->listing($actor,$v),
                'bambodia_invoice_get'=>$this->get($actor,$v['invoice_id']),
                'bambodia_invoices_summary'=>$this->summary($actor,$v),
                'bambodia_invoice_source_preview'=>$this->preview($actor,$v['order_reference']),
            };
        } catch (ModelNotFoundException $e) {
            $this->reject('resource','Authorized invoice or eligible source not found.');
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode()!==404) throw $e;
            $this->reject('resource','Authorized invoice or eligible source not found.');
        }
        $this->actor($owner,$mayRead);
        return $result;
    }

    private function scoped(User $actor,string $direction='both'): Builder
    {
        // Reuse the two native policy queries, combining their IDs without
        // unscoped lookups, global admin overrides or join multiplication.
        $q=MarketplaceInvoice::query()->whereIn('status',MarketplaceInvoice::STATUSES);
        return $q->where(function(Builder $q)use($actor,$direction): void {
            if ($direction!=='recipient')
                $q->whereIn('id',$this->policy->scoped($actor,true)->select('id'));
            if ($direction!=='issuer') {
                $ids=$this->policy->scoped($actor,false)->select('id');
                if ($direction==='recipient') $q->whereIn('id',$ids);
                else $q->orWhereIn('id',$ids);
            }
        });
    }

    private function detail(User $actor,MarketplaceInvoice $hint): array
    {
        $issuer=(int)$hint->issuer_id===(int)$actor->id;
        // Native method rechecks participant and current role while locked.
        return $this->native->detail($actor,$issuer,$hint->public_id,true);
    }

    private function data(array $d,bool $detail=false): array
    {
        $out=['invoice_id'=>$d['id'],'number'=>$d['number'],'relationship'=>$d['relationship'],
            'order_reference'=>$d['reference'],'currency'=>$d['currency'],'total'=>$d['total'],
            'status'=>$d['status'],'payment_state'=>$d['payment_state'],'due_on'=>$d['due_on'],
            'overdue'=>$d['overdue'],'created_at'=>$d['created_at'],'issued_at'=>$d['issued_at'],
            'voided_at'=>$d['voided_at'],'discarded_at'=>$d['discarded_at'],
            'outstanding_amount'=>$d['payment_state']==='review_required'?null:
                ($d['status']==='issued' && $d['payment_state']==='unpaid'?$d['total']:'0.00')];
        if ($detail) {
            // Only the canonical native line is supported in v1. An inconsistent
            // line snapshot is not presented as collectible or as a verified line.
            $out['items']=!$d['snapshot_valid']?[]:array_map(
                fn($l)=>['description'=>$l['description'],'quantity'=>$l['quantity'],'amount'=>$l['amount']],$d['items']);
            $out['description_is_untrusted_user_content']=true;
            if ($d['revision']!==null && $d['version']!==null) {
                $out['revision']=$d['revision']; $out['version']=$d['version'];
            }
        }
        return $out;
    }

    private function get(User $actor,string $id): array
    {
        return $this->data($this->detail($actor,$this->scoped($actor)->where('public_id',$id)->firstOrFail()),true);
    }

    private function listing(User $actor,array $v): array
    {
        $p=$this->scoped($actor,$v['direction']??'both')->when(isset($v['status']),fn($q)=>$q->where('status',$v['status']))
            ->orderByDesc('id')->paginate(20,['id','public_id','issuer_id'],page:$v['page']??1);
        $items=[];
        foreach($p as $hint) {
            $d=$this->detail($actor,$hint);
            if (isset($v['status']) && $d['status']!==$v['status']) $this->reject('resource','Invoice state changed. Read again.');
            $items[]=$this->data($d);
        }
        return ['items'=>$items,'page'=>$p->currentPage(),'per_page'=>20,'total'=>$p->total(),'last_page'=>$p->lastPage()];
    }

    private function preview(User $actor,string $ref): array
    {
        // The accepted native preview rejects any existing invoice, including
        // discarded, rather than returning a reusable source token.
        $d=$this->native->preview($actor,$ref,true);
        return ['order_reference'=>$d['reference'],'currency'=>$d['currency'],'total'=>$d['total'],
            'items'=>[['description'=>$d['description'],'quantity'=>1,'amount'=>$d['total']]],
            'description_is_untrusted_user_content'=>true,'source_version'=>$d['version'],'existing_invoice'=>false];
    }

    // Arbitrary-length decimal string accumulation, preserving exact cents even
    // when a report exceeds the individual DECIMAL(12,2) limit or PHP_INT_MAX.
    private static function add(string $sum,string $amount): string
    {
        $a=str_replace('.','',$sum);
        $b=(string)InvoiceMoney::cents($amount);
        $i=strlen($a)-1;$j=strlen($b)-1;$carry=0;$out='';
        while($i>=0 || $j>=0 || $carry) {
            $n=$carry+($i>=0?(int)$a[$i--]:0)+($j>=0?(int)$b[$j--]:0);
            $out=($n%10).$out;$carry=intdiv($n,10);
        }
        $out=str_pad(ltrim($out,'0'),3,'0',STR_PAD_LEFT);
        return substr($out,0,-2).'.'.substr($out,-2);
    }

    private function summary(User $actor,array $v): array
    {
        $days=$v['days']??30;
        $end=CarbonImmutable::now('UTC');
        $start=$end->subDays($days);
        $groups=[];$count=0;$review=0;$unpaid=0;$overdue=0;$outstanding='0.00';$overdueAmount='0.00';
        // Bounded creation window; per-document coherent snapshots (not a
        // point-in-time global financial ledger). No aggregate over hidden rows.
        $this->scoped($actor,$v['direction']??'both')->whereBetween('created_at',[$start,$end])
            ->chunkById(100,function($rows)use($actor,&$groups,&$count,&$review,&$unpaid,&$overdue,&$outstanding,&$overdueAmount): void {
                foreach($rows as $hint) {
                    $d=$this->detail($actor,$hint);
                    $count++;
                    if ($d['currency']!=='USD') $this->reject('resource','Unsupported invoice currency; review required.');
                    $key=$d['status'].':'.$d['payment_state'];
                    $g=$groups[$key]??['currency'=>'USD','status'=>$d['status'],'payment_state'=>$d['payment_state'],'count'=>0,'gross_total'=>'0.00'];
                    $g['count']++;$g['gross_total']=self::add($g['gross_total'],$d['total']);$groups[$key]=$g;
                    if ($d['payment_state']==='review_required') $review++;
                    if ($d['status']==='issued' && $d['payment_state']==='unpaid') {
                        $unpaid++;$outstanding=self::add($outstanding,$d['total']);
                        if ($d['overdue']) {$overdue++;$overdueAmount=self::add($overdueAmount,$d['total']);}
                    }
                }
            },'id');
        ksort($groups);
        return ['basis'=>'Visible documents created within rolling UTC window; current source states inspected per document; not revenue, settlement or all-time receivables.',
            'direction'=>$v['direction']??'both','days'=>$days,'window_start_utc'=>$start->toIso8601String(),
            'as_of_utc'=>$end->toIso8601String(),'display_timezone'=>config('invoices.timezone'),'count'=>$count,
            'groups'=>array_values($groups),'review_required_count'=>$review,'issued_unpaid_count'=>$unpaid,
            'issued_overdue_count'=>$overdue,'currency'=>'USD','issued_unpaid_total'=>$outstanding,'issued_overdue_total'=>$overdueAmount];
    }
}
