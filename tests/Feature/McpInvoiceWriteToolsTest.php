<?php

namespace Tests\Feature;

use App\Models\{MarketplaceInvoice,MarketplaceListing,MarketplaceOrder,User,CommercePaymentMethod};
use App\Support\Invoices\InvoiceDraftService;
use App\Support\Mcp\InvoiceReadTools;
use App\Support\Mcp\InvoiceWriteTools;
use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class McpInvoiceWriteToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['commerce.enabled'=>true,'invoices.enabled'=>true,'invoices.draft_writes_enabled'=>true,'mcp.production_writes_enabled'=>true]);
    }

    private function actor(string $role='super_admin'): User
    {
        return User::query()->create(['name'=>'PRIVATE_ACTOR','email'=>Str::uuid().'@example.test','password'=>'FixtureOnly123!',
            'role'=>$role,'account_status'=>'active']);
    }
    private function order(User $seller,User $buyer,string $amount='25.00'): MarketplaceOrder
    {
        $l=MarketplaceListing::query()->create(['user_id'=>$seller->id,'type'=>'service','title'=>'Original item',
            'description'=>'PRIVATE_DESCRIPTION','status'=>'published','price'=>'25.00','currency'=>'USD']);
        return MarketplaceOrder::query()->create(['number'=>(string)Str::uuid(),'buyer_id'=>$buyer->id,'seller_id'=>$seller->id,
            'marketplace_listing_id'=>$l->id,'request_key'=>(string)Str::uuid(),'listing_title'=>$l->title,
            'buyer_name'=>'PRIVATE_BUYER','seller_name'=>'PRIVATE_SELLER','total'=>$amount,'currency'=>'USD','status'=>'awaiting_payment']);
    }
    private function invoice(User $seller,MarketplaceOrder $o,string $status='draft',?string $due=null): MarketplaceInvoice
    {
        $svc=app(InvoiceDraftService::class);$p=$svc->preview($seller,$o->reference());
        $r=$svc->mutate($seller,'create',$o->reference(),$p['version'],(string)Str::uuid(),true);
        $i=MarketplaceInvoice::query()->where('public_id',$r['invoice_id'])->sole();
        // Synthetic future document states only, never an issuance operation.
        if($status!=='draft') $i->forceFill(['status'=>$status,'invoice_number'=>in_array($status,['issued','void'],true)?'QA-'.Str::uuid():null,
            'issued_at'=>in_array($status,['issued','void'],true)?now():null,'voided_at'=>$status==='void'?now():null,
            'discarded_at'=>$status==='discarded'?now():null,'due_on'=>$due])->save();
        return $i;
    }
    private function callTool(string $name,array $args,User $a,bool $read=true): array
    {return app(InvoiceReadTools::class)->call('bambodia_'.$name,$args,$a,$read);}
    private function rejects(callable $fn,string $field): array
    {
        try {$fn();$this->fail('Expected rejection.');}
        catch(ValidationException $e){$this->assertArrayHasKey($field,$e->errors());return $e->errors();}
    }
    private function snapshot(): array
    {
        $out=[];
        foreach(['marketplace_orders','commerce_payments','commerce_order_operations','marketplace_invoices','marketplace_invoice_items',
            'mcp_invoice_operations','audit_events','account_notifications','email_deliveries','product_entitlements','service_inquiries'] as $t)
            $out[$t]=DB::table($t)->orderBy('id')->get()->toJson();
        return $out;
    }


    private function write(string $action,array $args,User $a,bool $read=true,bool $write=true): array
    {return app(InvoiceWriteTools::class)->call('bambodia_invoice_draft_'.$action,$args,$a,$read,$write);}
    private function createArgs(User $a,MarketplaceOrder $o): array
    {
        $p=$this->callTool('invoice_source_preview',['order_reference'=>$o->reference()],$a);
        return ['order_reference'=>$o->reference(),'expected_version'=>$p['source_version'],'request_id'=>(string)Str::uuid(),'confirm'=>true];
    }
    private function args(array $r): array
    {return ['invoice_id'=>$r['invoice_id'],'expected_version'=>$r['version'],'request_id'=>(string)Str::uuid(),'confirm'=>true];}
    private function protected(): array
    {
        return array_diff_key($this->snapshot(),array_flip(['marketplace_invoices','marketplace_invoice_items','mcp_invoice_operations','audit_events']));
    }

    public function test_definitions_gates_strict_types_and_participant_boundaries(): void
    {
        $a=$this->actor();$b=$this->actor();$outsider=$this->actor();$staff=$this->actor('staff');
        $o=$this->order($a,$b);$args=$this->createArgs($a,$o);$before=$this->snapshot();
        $defs=InvoiceWriteTools::definitions(true);$this->assertSame(array_keys(InvoiceWriteTools::TOOLS),array_column($defs,'name'));
        foreach($defs as $d) {
            $this->assertSame(['readOnlyHint'=>false,'destructiveHint'=>str_ends_with($d['name'],'discard'),'idempotentHint'=>true,'openWorldHint'=>false],$d['annotations']);
            $this->assertFalse($d['inputSchema']['additionalProperties']);
            foreach([[$a,false,true],[$a,true,false],[$staff,true,true]] as [$actor,$read,$write])
                $this->rejects(fn()=>app(InvoiceWriteTools::class)->call($d['name'],[],$actor,$read,$write),'access');
        }
        foreach(['commerce.enabled','invoices.enabled','invoices.draft_writes_enabled','mcp.production_writes_enabled'] as $flag) {
            config([$flag=>false]);$this->rejects(fn()=>$this->write('create',$args,$a),'access');
            $this->assertSame([],InvoiceWriteTools::definitions((bool)config('mcp.production_writes_enabled')));
            config([$flag=>true]);
        }
        $this->assertSame([],InvoiceWriteTools::definitions(false));
        foreach([false,1,'true','1',null] as $invalid) $this->rejects(fn()=>$this->write('create',[...$args,'confirm'=>$invalid],$a),'confirm');
        $missing=$args;unset($missing['confirm']);$this->rejects(fn()=>$this->write('create',$missing,$a),'confirm');
        foreach(['total'=>'0.01','issuer_id'=>1,'status'=>'issued','notify'=>true] as $k=>$v)
            $this->rejects(fn()=>$this->write('create',[...$args,$k=>$v],$a),'arguments');
        foreach(['request_id'=>'bad','expected_version'=>'bad','order_reference'=>1234567890,'due_on'=>'2026-02-30'] as $k=>$v)
            $this->rejects(fn()=>$this->write('create',[...$args,$k=>$v],$a),$k);
        $e=$this->rejects(fn()=>$this->write('create',[...$args,'order_reference'=>'9999999999'],$a),'resource');
        foreach([$b,$outsider] as $other) $this->assertSame($e,$this->rejects(fn()=>$this->write('create',$args,$other),'resource'));
        $this->assertSame($before,$this->snapshot());
        $a->update(['account_status'=>'suspended']);$this->rejects(fn()=>$this->write('create',$args,$a),'access');
        $a->update(['account_status'=>'active','role'=>'freelancer']);$this->rejects(fn()=>$this->write('create',$args,$a),'access');
    }

    public function test_exact_replay_conflict_due_date_lifecycle_and_transactional_audit(): void
    {
        $a=$this->actor();$b=$this->actor();$o=$this->order($a,$b,'9999999999.99');
        $args=$this->createArgs($a,$o);$before=$this->protected();
        $r=$this->write('create',$args,$a);
        $this->assertSame(['invoice_id','status','revision','version','request_id','audit_id','replayed'],array_keys($r));
        $this->assertFalse($r['replayed']);$this->assertSame(1,$r['revision']);
        $this->assertSame([...$r,'replayed'=>true],$this->write('create',[...$args,'due_on'=>null],$a));
        $this->rejects(fn()=>$this->write('create',[...$args,'request_id'=>(string)Str::uuid()],$a),'conflict');
        $date=now(config('invoices.timezone'))->addDays(365)->toDateString();
        $this->rejects(fn()=>$this->write('create',[...$args,'due_on'=>$date],$a),'conflict');
        $u=$this->args($r);
        $this->rejects(fn()=>$this->write('update',$u,$a),'due_on');
        $this->rejects(fn()=>$this->write('update',[...$u,'due_on'=>null],$a),'conflict');
        $this->rejects(fn()=>$this->write('update',[...$u,'due_on'=>$date],$b),'resource');
        $updated=$this->write('update',[...$u,'due_on'=>$date],$a);
        $this->assertSame(2,$updated['revision']);
        $this->assertSame([...$updated,'replayed'=>true],$this->write('update',[...$u,'due_on'=>$date],$a));
        $this->rejects(fn()=>$this->write('update',[...$u,'request_id'=>(string)Str::uuid(),'due_on'=>null],$a),'conflict');
        $this->rejects(fn()=>$this->write('discard',$u,$a),'conflict');
        $clear=[...$this->args($updated),'due_on'=>null];$cleared=$this->write('update',$clear,$a);
        $discard=$this->args($cleared);$d=$this->write('discard',$discard,$a);
        $this->assertSame(4,$d['revision']);$this->assertSame('discarded',$d['status']);
        $this->assertSame([...$d,'replayed'=>true],$this->write('discard',$discard,$a));
        $this->assertSame([...$r,'replayed'=>true],$this->write('create',$args,$a));
        $this->assertSame([...$updated,'replayed'=>true],$this->write('update',[...$u,'due_on'=>$date],$a));
        $this->rejects(fn()=>$this->write('update',[...$this->args($d),'due_on'=>$date],$a),'conflict');
        $i=MarketplaceInvoice::query()->sole();$this->assertSame('9999999999.99',$i->total);$this->assertNull($i->due_on);
        $this->assertNull($i->invoice_number);$this->assertNull($i->issued_at);
        $this->assertSame('9999999999.99',$i->items()->sole()->line_total);
        $this->assertDatabaseCount('mcp_invoice_operations',4);
        foreach([[$r,'create'],[$updated,'update'],[$cleared,'update'],[$d,'discard']] as [$receipt,$action]) {
            $this->assertDatabaseHas('mcp_invoice_operations',['actor_id'=>$a->id,'request_id'=>$receipt['request_id'],'audit_event_id'=>$receipt['audit_id'],'tool'=>'invoice.draft.'.$action]);
            $this->assertDatabaseHas('audit_events',['id'=>$receipt['audit_id'],'actor_id'=>$a->id,'event'=>'mcp.invoice.draft.'.$action]);
        }
        $this->assertSame(4,AuditEvent::query()->where('event','like','invoice.draft.%')->count());
        $this->assertSame(4,AuditEvent::query()->where('event','like','mcp.invoice.draft.%')->count());
        $this->assertSame($before,$this->protected());
        $a->update(['account_status'=>'suspended']);$this->rejects(fn()=>$this->write('create',$args,$a),'access');
    }

    public function test_source_change_date_edges_and_discard_only_version(): void
    {
        $a=$this->actor();$b=$this->actor();$o=$this->order($a,$b);$args=$this->createArgs($a,$o);
        foreach([now(config('invoices.timezone'))->subDay()->toDateString(),now(config('invoices.timezone'))->addDays(366)->toDateString()] as $date)
            $this->rejects(fn()=>$this->write('create',[...$args,'due_on'=>$date],$a),'due_on');
        $o->update(['total'=>'26.00']);$this->rejects(fn()=>$this->write('create',$args,$a),'conflict');
        $args=$this->createArgs($a,$o);$r=$this->write('create',[...$args,'due_on'=>now(config('invoices.timezone'))->toDateString()],$a);
        $o->update(['status'=>'cancelled']);
        $get=$this->callTool('invoice_get',['invoice_id'=>$r['invoice_id']],$a);
        $this->assertArrayNotHasKey('version',$get);$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$get['discard_version']);
        $this->rejects(fn()=>$this->write('discard',$this->args($r),$a),'conflict');
        $u=[...$this->args($r),'expected_version'=>$get['discard_version']];
        $this->rejects(fn()=>$this->write('update',[...$u,'due_on'=>null],$a),'conflict');
        $d=$this->write('discard',$u,$a);$this->assertSame('discarded',$d['status']);
        $this->assertSame('cancelled',$o->fresh()->status);
        $this->assertSame([...$r,'replayed'=>true],$this->write('create',[...$args,'due_on'=>now(config('invoices.timezone'))->toDateString()],$a));
    }

    public function test_late_mcp_audit_failure_rolls_back_all_rows(): void
    {
        $a=$this->actor();$b=$this->actor();$o=$this->order($a,$b);$args=$this->createArgs($a,$o);$before=$this->snapshot();
        AuditEvent::creating(function(AuditEvent $event): void {if($event->event==='mcp.invoice.draft.create') throw new \RuntimeException('Synthetic late audit failure');});
        try {
            try {$this->write('create',$args,$a);$this->fail('Expected rollback');}
            catch(\RuntimeException $e){$this->assertSame('Synthetic late audit failure',$e->getMessage());}
            $this->assertSame($before,$this->snapshot());
        } finally {AuditEvent::flushEventListeners();}
        $r=$this->write('create',$args,$a);$this->assertFalse($r['replayed']);
        $this->assertDatabaseCount('mcp_invoice_operations',1);
    }
}
