<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CommercePaymentMethod;
use App\Models\MarketplaceInvoice;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\User;
use App\Support\Invoices\InvoiceDraftService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class NativeInvoiceFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
    }

    private function enable(bool $writes=true): void
    {
        config(['commerce.enabled'=>true,'invoices.enabled'=>true,'invoices.draft_writes_enabled'=>$writes]);
    }

    private function user(string $role): User
    {
        return User::query()->create(['name'=>'Invoice fixture','email'=>Str::uuid().'@example.test',
            'password'=>'fixture-password-only','role'=>$role,'account_status'=>'active']);
    }

    private function fixture(string $amount='25.00',string $currency='USD',string $status='awaiting_payment'): array
    {
        $seller=$this->user('freelancer'); $buyer=$this->user('customer');
        $listing=MarketplaceListing::query()->create(['user_id'=>$seller->id,'type'=>'service','title'=>'Original invoice item',
            'description'=>'Synthetic fixture description','status'=>'published','currency'=>'USD','price'=>'25.00']);
        $order=MarketplaceOrder::query()->create(['number'=>(string)Str::uuid(),'buyer_id'=>$buyer->id,'seller_id'=>$seller->id,
            'marketplace_listing_id'=>$listing->id,'request_key'=>(string)Str::uuid(),'listing_title'=>$listing->title,
            'buyer_name'=>$buyer->name,'seller_name'=>$seller->name,'total'=>$amount,'currency'=>$currency,'status'=>$status]);
        return [$seller,$buyer,$order,$listing];
    }

    private function svc(): InvoiceDraftService { return app(InvoiceDraftService::class); }

    private function draft(User $seller,MarketplaceOrder $order,?string $date=null): array
    {
        $p=$this->svc()->preview($seller,$order->reference());
        return $this->svc()->mutate($seller,'create',$order->reference(),$p['version'],(string)Str::uuid(),true,$date);
    }

    private function snapshot(): array
    {
        $tables=['marketplace_orders','commerce_payments','commerce_order_operations','account_notifications','email_deliveries','product_entitlements','service_inquiries'];
        return array_map(fn($t)=>DB::table($t)->orderBy('id')->get()->toJson(),$tables);
    }

    private function rejects(callable $action,int $status): void
    {
        try { $action(); $this->fail('Expected HTTP rejection.'); }
        catch (HttpException $e) { $this->assertSame($status,$e->getStatusCode()); }
    }

    public function test_defaults_hidden_and_read_only_switch_never_enables_draft_writes(): void
    {
        [$seller,$buyer,$order]=$this->fixture();
        config(['commerce.enabled'=>true,'invoices.enabled'=>false,'invoices.draft_writes_enabled'=>false]);
        $this->assertFalse(config('invoices.enabled'));
        $this->get(route('studio.invoices.index'))->assertRedirect(route('login'));
        $this->actingAs($seller)->get(route('studio.invoices.index'))->assertNotFound();
        $this->get(route('studio.invoices.preview',['order_reference'=>$order->reference()]))->assertNotFound();
        $this->actingAs($buyer)->get(route('account.invoices.index'))->assertNotFound();
        $this->enable(false);
        $this->actingAs($seller)->get(route('studio.invoices.index'))->assertOk()->assertDontSee('New invoice draft');
        $this->postJson(route('studio.invoices.store'),[])->assertNotFound();
        $this->assertDatabaseCount('marketplace_invoices',0);
        $this->assertDatabaseCount('mcp_invoice_operations',0);
    }

    public function test_private_native_ui_draft_snapshot_and_owner_boundaries(): void
    {
        $this->enable();
        [$seller,$buyer,$order,$listing]=$this->fixture('25.00');
        $listing->update(['title'=>'Changed listing','price'=>'99.00']);
        $before=$this->snapshot();
        $this->actingAs($seller);
        $this->get(route('studio.invoices.index'))->assertOk()->assertSee('New invoice draft');
        $preview=$this->get(route('studio.invoices.preview',['order_reference'=>$order->reference()]))->assertOk()
            ->assertHeader('Cache-Control','no-store, private')->assertSee('Original invoice item')->assertDontSee('Changed listing');
        $data=$preview->getOriginalContent()->getData();
        $args=['order_reference'=>$order->reference(),'expected_version'=>$data['source']['version'],
            'request_id'=>$data['requestId'],'confirm'=>'1','due_on'=>null];
        $this->postJson(route('studio.invoices.store'),[...$args,'total'=>'1.00'])->assertUnprocessable();
        $this->postJson(route('studio.invoices.store'),[...$args,'confirm'=>true])->assertUnprocessable();
        $this->post(route('studio.invoices.store'),$args)->assertRedirect();
        $i=MarketplaceInvoice::query()->sole();
        $this->assertSame('25.00',$i->total);
        $this->assertNull($i->invoice_number);
        $this->assertNull($i->issued_at);
        $this->assertSame('draft',$i->status);
        $this->assertSame('Original invoice item',$i->items()->sole()->description);
        $this->assertSame(1,$i->items()->sole()->quantity);
        $this->assertSame($before,$this->snapshot());
        $this->get(route('studio.invoices.show',$i->public_id))->assertOk()->assertSee('NOT A TAX INVOICE')
            ->assertDontSee($buyer->email)->assertSee('25.00');
        $this->post(route('studio.invoices.store'),$args)->assertRedirect(route('studio.invoices.show',$i->public_id));
        $this->assertDatabaseCount('marketplace_invoices',1);
        $this->assertDatabaseCount('mcp_invoice_operations',1);
        $this->actingAs($buyer)->get(route('account.invoices.index'))->assertOk()->assertSee('No invoices found.')->assertDontSee($i->public_id);
        $this->get(route('account.invoices.show',$i->public_id))->assertNotFound();
        $this->actingAs($this->user('super_admin'))->get(route('studio.invoices.show',$i->public_id))->assertNotFound();
        $this->get(route('studio.invoices.preview',['order_reference'=>$order->reference()]))->assertNotFound();
        $this->assertSame($before,$this->snapshot());
    }

    public function test_versions_replay_conflict_update_clear_and_terminal_discard(): void
    {
        $this->enable();
        [$seller,$buyer,$order]=$this->fixture();
        $before=$this->snapshot();
        $p=$this->svc()->preview($seller,$order->reference());
        $uuid=(string)Str::uuid();
        $r=$this->svc()->mutate($seller,'create',$order->reference(),$p['version'],$uuid,true);
        $this->assertFalse($r['replayed']);
        $this->assertSame([...$r,'replayed'=>true],$this->svc()->mutate($seller,'create',$order->reference(),$p['version'],$uuid,true));
        $this->rejects(fn()=>$this->svc()->mutate($seller,'create',$order->reference(),$p['version'],(string)Str::uuid(),true),409);
        $date=now(config('invoices.timezone'))->addDay()->toDateString();
        $this->rejects(fn()=>$this->svc()->mutate($seller,'create',$order->reference(),$p['version'],$uuid,true,$date),409);
        $u=$this->svc()->mutate($seller,'update',$r['invoice_id'],$r['version'],(string)Str::uuid(),true,$date);
        $this->assertSame(2,$u['revision']);
        $this->rejects(fn()=>$this->svc()->mutate($seller,'update',$r['invoice_id'],$r['version'],(string)Str::uuid(),true,null),409);
        $clear=$this->svc()->mutate($seller,'update',$r['invoice_id'],$u['version'],(string)Str::uuid(),true,null);
        $this->assertNull(MarketplaceInvoice::query()->sole()->due_on);
        $d=$this->svc()->mutate($seller,'discard',$r['invoice_id'],$clear['version'],(string)Str::uuid(),true);
        $this->assertSame('discarded',$d['status']);
        $this->assertSame(4,$d['revision']);
        $this->assertDatabaseCount('marketplace_invoice_items',1);
        $this->assertDatabaseCount('mcp_invoice_operations',4);
        $this->assertSame(4,AuditEvent::query()->where('event','like','invoice.draft.%')->count());
        foreach ([$r,$u,$clear,$d] as $receipt) {
            $this->assertDatabaseHas('mcp_invoice_operations',['actor_id'=>$seller->id,'request_id'=>$receipt['request_id'],'audit_event_id'=>$receipt['audit_id']]);
            $this->assertDatabaseHas('audit_events',['id'=>$receipt['audit_id'],'actor_id'=>$seller->id]);
        }
        $this->rejects(fn()=>$this->svc()->mutate($seller,'update',$r['invoice_id'],$d['version'],(string)Str::uuid(),true,$date),409);
        $this->rejects(fn()=>$this->svc()->preview($seller,$order->reference()),404);
        $this->assertSame($before,$this->snapshot());
    }

    public function test_source_eligibility_and_state_transition_freeze(): void
    {
        $this->enable();
        foreach ([['0.00','USD','awaiting_payment'],['1.00','KHR','awaiting_payment'],['1.00','USD','completed'],
            ['1.00','USD','refunded'],['1.00','USD','cancelled']] as [$a,$c,$s]) {
            [$seller,$buyer,$order]=$this->fixture($a,$c,$s);
            $this->rejects(fn()=>$this->svc()->preview($seller,$order->reference()),404);
        }
        [$seller,$buyer,$order]=$this->fixture();
        $buyer->update(['account_status'=>'suspended']);
        $this->rejects(fn()=>$this->svc()->preview($seller,$order->reference()),404);
        $buyer->update(['account_status'=>'active']);
        $p=$this->svc()->preview($seller,$order->reference());
        $order->update(['status'=>'cancelled']);
        $this->rejects(fn()=>$this->svc()->mutate($seller,'create',$order->reference(),$p['version'],(string)Str::uuid(),true),404);
        $order->update(['status'=>'awaiting_payment']);
        $r=$this->draft($seller,$order);
        $order->update(['status'=>'cancelled']);
        $d=$this->svc()->detail($seller,true,$r['invoice_id']);
        $this->assertSame('cancelled',$d['payment_state']);
        $this->assertFalse($d['can_update']); $this->assertTrue($d['can_discard']);
        $this->rejects(fn()=>$this->svc()->mutate($seller,'update',$r['invoice_id'],$d['version'],(string)Str::uuid(),true,now(config('invoices.timezone'))->toDateString()),409);
        $this->svc()->mutate($seller,'discard',$r['invoice_id'],$d['version'],(string)Str::uuid(),true);
        $this->assertSame('cancelled',$order->refresh()->status);
    }

    public function test_payment_state_is_derived_without_mutation_and_test_payment_is_not_paid(): void
    {
        $this->enable();
        [$seller,$buyer,$order]=$this->fixture();
        $r=$this->draft($seller,$order);
        $method=CommercePaymentMethod::query()->create(['name'=>'Invoice synthetic bank','provider'=>'bank_transfer']);
        $payment=$order->payments()->create(['number'=>(string)Str::uuid(),'commerce_payment_method_id'=>$method->id,
            'provider'=>'bank_transfer','method_name'=>'Fixture bank','live_mode'=>false,'status'=>'paid','settlement_reference'=>'fixture-only']);
        $order->update(['status'=>'completed','receipt_number'=>(string)Str::uuid(),'completed_at'=>now()]);
        $this->assertSame('review_required',$this->svc()->detail($seller,true,$r['invoice_id'])['payment_state']);
        $payment->update(['live_mode'=>true]);
        $before=$this->snapshot();
        $d=$this->svc()->detail($seller,true,$r['invoice_id']);
        $this->assertSame('paid',$d['payment_state']);
        $this->assertSame('draft',$d['status']);
        $this->assertFalse($d['can_update']);
        $this->assertSame($before,$this->snapshot());
    }

    public function test_invalid_due_date_and_missing_confirmation_leave_no_records(): void
    {
        $this->enable();
        [$seller,$buyer,$order]=$this->fixture();
        $p=$this->svc()->preview($seller,$order->reference());
        $this->actingAs($seller);
        $base=['order_reference'=>$order->reference(),'expected_version'=>$p['version'],'request_id'=>(string)Str::uuid(),'confirm'=>'1'];
        foreach ([null,'0','true'] as $value) $this->postJson(route('studio.invoices.store'),[...$base,'confirm'=>$value])->assertUnprocessable();
        $this->postJson(route('studio.invoices.store'),[...$base,'due_on'=>'2026-02-30'])->assertUnprocessable();
        $this->postJson(route('studio.invoices.store'),[...$base,'due_on'=>now(config('invoices.timezone'))->addDays(366)->toDateString()])->assertUnprocessable();
        $this->assertDatabaseCount('marketplace_invoices',0);
        $this->assertDatabaseCount('mcp_invoice_operations',0);
    }

    public function test_late_audit_failure_rolls_back_invoice_item_and_operation(): void
    {
        $this->enable();
        [$seller,$buyer,$order]=$this->fixture();
        $before=$this->snapshot();
        $p=$this->svc()->preview($seller,$order->reference());
        AuditEvent::creating(function(AuditEvent $event): void {
            if ($event->event==='invoice.draft.create') throw new \RuntimeException('Synthetic late audit failure');
        });
        try {
            $this->svc()->mutate($seller,'create',$order->reference(),$p['version'],(string)Str::uuid(),true);
            $this->fail('Expected synthetic rollback.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic late audit failure',$e->getMessage());
        }
        $this->assertDatabaseCount('marketplace_invoices',0);
        $this->assertDatabaseCount('marketplace_invoice_items',0);
        $this->assertDatabaseCount('mcp_invoice_operations',0);
        $this->assertSame(0,AuditEvent::query()->where('event','like','invoice.draft.%')->count());
        $this->assertSame($before,$this->snapshot());
    }
}
