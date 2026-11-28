<?php

namespace Tests\Feature;

use App\Models\{MarketplaceInvoice,MarketplaceListing,MarketplaceOrder,User,CommercePaymentMethod};
use App\Support\Invoices\InvoiceDraftService;
use App\Support\Mcp\InvoiceReadTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class McpInvoiceReadToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['commerce.enabled'=>true,'invoices.enabled'=>true,'invoices.draft_writes_enabled'=>true]);
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

    public function test_registry_flags_scope_strict_arguments_and_current_actor(): void
    {
        $a=$this->actor();$staff=$this->actor('staff');$inactive=$this->actor();$inactive->update(['account_status'=>'deactivated']);
        $defs=InvoiceReadTools::definitions();
        $this->assertSame(array_keys(InvoiceReadTools::TOOLS),array_column($defs,'name'));
        foreach($defs as $d){
            $this->assertSame(['readOnlyHint'=>true,'destructiveHint'=>false,'idempotentHint'=>true,'openWorldHint'=>false],$d['annotations']);
            $this->assertFalse($d['inputSchema']['additionalProperties']);
            foreach([[$a,false],[$staff,true],[$inactive,true]] as [$owner,$read])
                $this->rejects(fn()=>app(InvoiceReadTools::class)->call($d['name'],[],$owner,$read),'access');
        }
        foreach([
            ['invoices_list',['page'=>'1'],'page'],['invoices_list',['page'=>1.5],'page'],['invoices_list',['page'=>1001],'page'],
            ['invoices_list',['direction'=>'all'],'direction'],['invoices_list',['status'=>'paid'],'status'],
            ['invoices_list',['actor_id'=>1],'arguments'],['invoice_get',['invoice_id'=>null],'invoice_id'],
            ['invoice_get',['invoice_id'=>strtoupper((string)Str::uuid())],'invoice_id'],
            ['invoice_get',[],'invoice_id'],['invoices_summary',['days'=>0],'days'],['invoices_summary',['days'=>91],'days'],
            ['invoices_summary',['days'=>true],'days'],['invoice_source_preview',['order_reference'=>'0123456789'],'order_reference'],
            ['invoice_source_preview',['order_reference'=>1234567890],'order_reference'],
        ] as [$name,$args,$field]) $this->rejects(fn()=>$this->callTool($name,$args,$a),$field);
        foreach(['invoices.enabled','commerce.enabled'] as $flag) {
            config([$flag=>false]);$this->assertSame([],InvoiceReadTools::definitions());
            foreach(array_keys(InvoiceReadTools::TOOLS) as $name)
                $this->rejects(fn()=>app(InvoiceReadTools::class)->call($name,[],$a,true),'access');
            config([$flag=>true]);
        }
        config(['invoices.draft_writes_enabled'=>false]);$this->assertCount(4,InvoiceReadTools::definitions());
        User::query()->whereKey($a->id)->update(['role'=>'staff']);
        $this->rejects(fn()=>$this->callTool('invoices_list',[],$a),'access');
    }

    public function test_participant_visibility_privacy_source_and_zero_mutation(): void
    {
        $a=$this->actor();$b=$this->actor();$other=$this->actor();$outsider=$this->actor();
        $draft=$this->invoice($a,$this->order($a,$b));
        $discarded=$this->invoice($a,$this->order($a,$b),'discarded');
        $issued=$this->invoice($b,$this->order($b,$a),'issued');
        $void=$this->invoice($b,$this->order($b,$a),'void');
        $hidden=$this->invoice($other,$this->order($other,$outsider),'issued');
        $source=$this->order($a,$b);
        $source->listing()->update(['title'=>'Changed item','price'=>'99.00']);
        config(['invoices.draft_writes_enabled'=>false]);
        $before=$this->snapshot();
        $list=$this->callTool('invoices_list',[],$a);
        $this->assertSame(4,$list['total']);
        $this->assertSame(2,$this->callTool('invoices_list',['direction'=>'issuer'],$a)['total']);
        $this->assertSame(2,$this->callTool('invoices_list',['direction'=>'recipient'],$a)['total']);
        $this->assertSame(0,$this->callTool('invoices_list',['direction'=>'recipient','status'=>'draft'],$b)['total']);
        $this->assertSame(0,$this->callTool('invoices_list',['direction'=>'recipient','status'=>'discarded'],$b)['total']);
        $d=$this->callTool('invoice_get',['invoice_id'=>$draft->public_id],$a);
        $this->assertSame('25.00',$d['total']);$this->assertSame('0.00',$d['outstanding_amount']);
        $this->assertSame(1,$d['revision']);$this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/',$d['version']);
        foreach([$issued,$void] as $i) {
            $d=$this->callTool('invoice_get',['invoice_id'=>$i->public_id],$a);
            $this->assertSame('recipient',$d['relationship']);$this->assertArrayNotHasKey('version',$d);$this->assertArrayNotHasKey('revision',$d);
        }
        $notFound=$this->rejects(fn()=>$this->callTool('invoice_get',['invoice_id'=>(string)Str::uuid()],$a),'resource');
        foreach([[$b,$draft],[$b,$discarded],[$a,$hidden]] as [$actor,$i])
            $this->assertSame($notFound,$this->rejects(fn()=>$this->callTool('invoice_get',['invoice_id'=>$i->public_id],$actor),'resource'));
        $p=$this->callTool('invoice_source_preview',['order_reference'=>$source->reference()],$a);
        $this->assertSame('Original item',$p['items'][0]['description']);$this->assertSame('25.00',$p['total']);
        $this->assertSame(app(InvoiceDraftService::class)->preview($a,$source->reference())['version'],$p['source_version']);
        $this->assertFalse($p['existing_invoice']);
        foreach([[$b,$source->reference()],[$a,$draft->order->reference()]] as [$actor,$ref])
            $this->assertSame($notFound,$this->rejects(fn()=>$this->callTool('invoice_source_preview',['order_reference'=>$ref],$actor),'resource'));
        $summary=$this->callTool('invoices_summary',[],$a);$this->assertSame(4,$summary['count']);
        foreach(['PRIVATE_','@example.test','snapshot_digest','issuer_id','recipient_id','settlement_reference','receipt_number','audit_id','request_id'] as $private)
            $this->assertStringNotContainsString($private,json_encode([$list,$d,$p,$summary],JSON_THROW_ON_ERROR));
        $this->assertSame($before,$this->snapshot());
    }

    public function test_pagination_window_and_exact_summary_above_single_invoice_limit(): void
    {
        $a=$this->actor();$b=$this->actor();
        for($i=0;$i<21;$i++) $this->invoice($a,$this->order($a,$b,'9999999999.99'),'issued',now(config('invoices.timezone'))->subDay()->toDateString());
        $old=$this->invoice($a,$this->order($a,$b,'0.01'),'issued');$old->forceFill(['created_at'=>now()->subDays(91)])->save();
        $before=$this->snapshot();
        $p1=$this->callTool('invoices_list',[],$a);$p2=$this->callTool('invoices_list',['page'=>2],$a);
        $this->assertSame(22,$p1['total']);$this->assertCount(20,$p1['items']);$this->assertCount(2,$p2['items']);
        $this->assertSame([],array_intersect(array_column($p1['items'],'invoice_id'),array_column($p2['items'],'invoice_id')));
        $this->assertSame([],$this->callTool('invoices_list',['page'=>1000],$a)['items']);
        $s=$this->callTool('invoices_summary',['days'=>90],$a);
        $this->assertSame(21,$s['count']);$this->assertSame(21,$s['issued_unpaid_count']);$this->assertSame(21,$s['issued_overdue_count']);
        $this->assertSame('209999999999.79',$s['issued_unpaid_total']);$this->assertSame($s['issued_unpaid_total'],$s['issued_overdue_total']);
        $this->assertSame('209999999999.79',$s['groups'][0]['gross_total']);
        $this->assertSame($before,$this->snapshot());
    }

    public function test_payment_state_does_not_infer_collection_and_preview_rejects_ineligible_sources(): void
    {
        $a=$this->actor();$b=$this->actor();
        $o=$this->order($a,$b,'0.01');$i=$this->invoice($a,$o,'issued');
        $method=CommercePaymentMethod::query()->firstOrCreate(['name'=>'Invoice fixture bank'],['available'=>true]);
        $payment=$o->payments()->create(['number'=>(string)Str::uuid(),'commerce_payment_method_id'=>$method->id,
            'method_name'=>$method->name,'provider'=>'bank_transfer','status'=>'paid','live_mode'=>false,'settlement_reference'=>'SECRET-'.Str::uuid()]);
        $o->update(['status'=>'completed','receipt_number'=>(string)Str::uuid(),'completed_at'=>now()]);
        $get=fn()=>$this->callTool('invoice_get',['invoice_id'=>$i->public_id],$a);
        $this->assertSame('review_required',$get()['payment_state']);$this->assertNull($get()['outstanding_amount']);
        $payment->update(['live_mode'=>true]);
        $this->assertSame('paid',$get()['payment_state']);$this->assertSame('0.00',$get()['outstanding_amount']);
        $o->update(['status'=>'refunded']);
        $this->assertSame('review_required',$get()['payment_state']);
        $o->update(['status'=>'completed']);
        $o->payments()->create(['number'=>(string)Str::uuid(),'commerce_payment_method_id'=>$method->id,
            'method_name'=>$method->name,'provider'=>'bank_transfer','status'=>'paid','live_mode'=>true,'settlement_reference'=>'SECRET-'.Str::uuid()]);
        $this->assertSame('review_required',$get()['payment_state']);
        $s=$this->callTool('invoices_summary',[],$a);$this->assertSame(1,$s['review_required_count']);$this->assertSame('0.00',$s['issued_unpaid_total']);
        foreach(['cancelled','completed','refunded'] as $status) {
            $o=$this->order($a,$b);$o->update(['status'=>$status]);
            $this->rejects(fn()=>$this->callTool('invoice_source_preview',['order_reference'=>$o->reference()],$a),'resource');
        }
        $zero=$this->order($a,$b,'0.00');
        $this->rejects(fn()=>$this->callTool('invoice_source_preview',['order_reference'=>$zero->reference()],$a),'resource');
    }
}
