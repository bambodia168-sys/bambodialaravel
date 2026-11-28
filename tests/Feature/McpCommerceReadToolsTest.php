<?php

namespace Tests\Feature;

use App\Models\CommercePaymentMethod;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\ProductEntitlement;
use App\Models\ProductFileVersion;
use App\Models\ProductLicense;
use App\Models\User;
use App\Support\Mcp\CommerceReadTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class McpCommerceReadToolsTest extends TestCase
{
    use RefreshDatabase;

    private function actor(string $role='super_admin'): User
    {
        $n=User::query()->count()+1;
        return User::query()->create(['name'=>'Secret person '.$n,'username'=>'phase4_'.$n,
            'email'=>'phase4_'.$n.'@example.test','password'=>'TestPassword123!','role'=>$role,'locale'=>'en']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.enabled'=>true]);
        Http::preventStrayRequests();
    }

    private function listing(User $seller): MarketplaceListing
    {
        return MarketplaceListing::query()->create(['user_id'=>$seller->id,'type'=>'product',
            'title'=>'Phase 4 product','description'=>'Disposable commerce fixture','currency'=>'USD','status'=>'draft']);
    }

    private function orderFixture(User $buyer,User $seller,string $status='completed',string $total='12.34',string $currency='USD'): MarketplaceOrder
    {
        return MarketplaceOrder::query()->create(['number'=>(string)Str::uuid(),'request_key'=>(string)Str::uuid(),
            'buyer_id'=>$buyer->id,'seller_id'=>$seller->id,'marketplace_listing_id'=>$this->listing($seller)->id,
            'listing_title'=>'Order fixture','buyer_name'=>'PRIVATE_BUYER','seller_name'=>'PRIVATE_SELLER',
            'status'=>$status,'total'=>$total,'currency'=>$currency,
            'receipt_number'=>(string)Str::uuid(),'completed_at'=>now()]);
    }

    private function paid(MarketplaceOrder $order,bool $live=true,bool $settled=true): void
    {
        $method=CommercePaymentMethod::query()->firstOrFail();
        $order->payments()->create(['number'=>(string)Str::uuid(),'commerce_payment_method_id'=>$method->id,
            'provider'=>'stripe','method_name'=>'Stripe','live_mode'=>$live,'status'=>'paid',
            'settlement_reference'=>$settled?'SECRET-'.Str::uuid():null,'remote_id'=>'REMOTE-'.Str::uuid(),
            'proof_path'=>'private/secret.pdf','qr_payload'=>'PRIVATE_QR']);
    }

    private function licenseFixture(User $customer,User $creator): ProductLicense
    {
        $product=$this->listing($creator);
        $def=LicenseDefinition::query()->create(['code'=>'PH4_'.Str::random(6),'name'=>'Fixture license','version'=>'1.0.0',
            'permissions'=>'Fixture permissions','restrictions'=>'Fixture restrictions','status'=>'active','created_by'=>$creator->id]);
        $release=ProductFileVersion::query()->create(['marketplace_listing_id'=>$product->id,'uploaded_by'=>$creator->id,
            'version'=>'1.0.0','release_notes'=>'Fixture release','original_filename'=>'secret.zip','storage_path'=>'private/'.Str::uuid().'.zip',
            'file_type'=>'zip','byte_size'=>100,'sha256'=>hash('sha256',(string)Str::uuid()),'status'=>'approved','rights_attested_at'=>now()]);
        $entitlement=ProductEntitlement::query()->create(['user_id'=>$customer->id,'product_file_version_id'=>$release->id,
            'granted_by'=>$creator->id,'grant_reason'=>'Fixture grant','status'=>'active','expires_at'=>now()->subDay()]);
        $license=ProductLicense::query()->create(['product_entitlement_id'=>$entitlement->id,'marketplace_listing_id'=>$product->id,
            'product_file_version_id'=>$release->id,'user_id'=>$customer->id,'license_definition_id'=>$def->id,'issued_by'=>$creator->id,
            'tier'=>'regular','source'=>'admin_complimentary','key_hash'=>hash('sha256',(string)Str::uuid()),
            'key_prefix'=>'SECRET-PREFIX','key_ciphertext'=>'SECRET-KEY','status'=>'active','max_domains'=>1,'pending_domain'=>'private.example.test']);
        $license->activations()->create(['domain'=>'private.example.test','status'=>'active','activated_at'=>now()]);
        return $license;
    }

    private function reject(callable $fn,string $field): void
    {
        try { $fn(); $this->fail('Expected rejection: '.$field); }
        catch (ValidationException $e) { $this->assertArrayHasKey($field,$e->errors()); }
    }

    public function test_registry_strict_validation_and_fresh_authorization(): void
    {
        $a=$this->actor(); $staff=$this->actor('staff'); $inactive=$this->actor();
        $inactive->update(['account_status'=>'deactivated']);
        $s=new CommerceReadTools();
        $defs=$s->definitions();
        $this->assertCount(6,$defs);
        $this->assertSame(array_keys(CommerceReadTools::TOOLS),array_column($defs,'name'));
        foreach ($defs as $d) {
            $this->assertTrue($d['annotations']['readOnlyHint']);
            $this->assertFalse($d['inputSchema']['additionalProperties']);
            foreach ([[$a,false],[$staff,true],[$inactive,true]] as [$owner,$read])
                $this->reject(fn()=>$s->call($d['name'],[],$owner,$read),'access');
        }
        foreach ([
            ['bambodia_orders_list',['page'=>'1'],'page'],
            ['bambodia_orders_list',['page'=>1001],'page'],
            ['bambodia_orders_list',['user_id'=>2],'arguments'],
            ['bambodia_orders_list',['direction'=>'all'],'direction'],
            ['bambodia_order_get',['reference'=>1234567890],'reference'],
            ['bambodia_order_get',['reference'=>'0123456789'],'reference'],
            ['bambodia_transactions_summary',['days'=>91],'days'],
            ['bambodia_license_get',['license_id'=>'invalid'],'license_id'],
            ['bambodia_licenses_list',['scope'=>'all'],'scope'],
        ] as [$name,$args,$field]) $this->reject(fn()=>$s->call($name,$args,$a,true),$field);
        config(['commerce.enabled'=>false]);
        foreach ($defs as $d) $this->reject(fn()=>$s->call($d['name'],[],$a,true),'access');
        config(['commerce.enabled'=>true]);
        User::query()->whereKey($a->id)->update(['role'=>'staff']);
        $this->reject(fn()=>$s->call('bambodia_orders_list',[],$a,true),'access');
    }

    public function test_orders_receipts_and_transactions_are_participant_scoped_and_live_only(): void
    {
        $a=$this->actor(); $b=$this->actor(); $c=$this->actor();
        $own=$this->orderFixture($a,$b); $this->paid($own); $this->paid($own);
        $sold=$this->orderFixture($b,$a,'refunded','20.00','KHR'); $this->paid($sold);
        $test=$this->orderFixture($a,$b); $this->paid($test,false);
        $unsettled=$this->orderFixture($a,$b); $this->paid($unsettled,true,false);
        $free=$this->orderFixture($a,$b,'completed','0.00');
        $foreign=$this->orderFixture($b,$c); $this->paid($foreign);
        $s=new CommerceReadTools();
        $before=DB::table('marketplace_orders')->orderBy('id')->get()->toJson();
        $payments=DB::table('commerce_payments')->orderBy('id')->get()->toJson();
        $list=$s->call('bambodia_orders_list',[],$a,true);
        $this->assertSame(5,$list['total']);
        $this->assertSame(4,$s->call('bambodia_orders_list',['direction'=>'buyer'],$a,true)['total']);
        $this->assertSame(1,$s->call('bambodia_orders_list',['direction'=>'seller'],$a,true)['total']);
        $this->assertSame(1,$s->call('bambodia_orders_list',['status'=>'refunded'],$a,true)['total']);
        $get=fn($o)=>$s->call('bambodia_order_get',['reference'=>$o->reference()],$a,true);
        $receipt=fn($o)=>$s->call('bambodia_receipt_get',['reference'=>$o->reference()],$a,true);
        $this->assertSame(['reference','listing_title','relationship','total','currency','status',
            'verified_live_payment','receipt_status','created_at','completed_at','cancelled_at'],array_keys($get($own)));
        $this->assertSame(['reference','status','currency','total','completed_at'],array_keys($receipt($own)));
        $this->assertTrue($get($own)['verified_live_payment']);
        $this->assertFalse($get($test)['verified_live_payment']);
        $this->assertFalse($get($unsettled)['verified_live_payment']);
        $this->assertSame('Paid',$receipt($own)['status']);
        $this->assertSame('Refunded',$receipt($sold)['status']);
        $this->assertSame('No payment required',$receipt($free)['status']);
        foreach ([$test,$unsettled,$foreign] as $o) $this->reject(fn()=>$receipt($o),'reference');
        $this->reject(fn()=>$get($foreign),'reference');
        $rows=$s->call('bambodia_transactions_summary',[],$a,true)['by_currency_and_status'];
        $this->assertCount(2,$rows);
        $this->assertSame('KHR',$rows[0]['currency']);
        $this->assertSame('refunded',$rows[0]['status']);
        $this->assertSame(1,$rows[0]['orders']);
        $this->assertSame(1,$rows[1]['orders']);
        $this->assertEquals(12.34,(float)$rows[1]['gross_order_amount']);
        $json=json_encode([$list,$get($own),$receipt($own),$rows]);
        foreach (['PRIVATE_BUYER','PRIVATE_SELLER','SECRET-','REMOTE-','PRIVATE_QR','proof_path','settlement_reference','receipt_number',$own->receipt_number,$own->number] as $secret)
            $this->assertStringNotContainsString($secret,$json);
        $this->assertSame($before,DB::table('marketplace_orders')->orderBy('id')->get()->toJson());
        $this->assertSame($payments,DB::table('commerce_payments')->orderBy('id')->get()->toJson());
    }

    public function test_license_visibility_validity_and_secret_redaction(): void
    {
        $a=$this->actor(); $b=$this->actor(); $c=$this->actor();
        $held=$this->licenseFixture($a,$b); $sold=$this->licenseFixture($b,$a); $foreign=$this->licenseFixture($b,$c);
        $s=new CommerceReadTools();
        $before=DB::table('product_licenses')->orderBy('id')->get()->toJson();
        $list=$s->call('bambodia_licenses_list',[],$a,true);
        $this->assertSame(2,$list['total']);
        $this->assertSame(1,$s->call('bambodia_licenses_list',['scope'=>'customer'],$a,true)['total']);
        $this->assertSame(1,$s->call('bambodia_licenses_list',['scope'=>'creator'],$a,true)['total']);
        $get=fn($l)=>$s->call('bambodia_license_get',['license_id'=>$l->public_id],$a,true);
        $row=$get($held);
        $this->assertSame(['license_id','product_id','product_title','relationship','tier','status',
            'installation_active','download_entitlement_active','max_domains','active_activations','support_status','support_expires_at'],array_keys($row));
        $this->assertTrue($row['installation_active']);
        $this->assertFalse($row['download_entitlement_active']);
        $this->assertSame(1,$row['active_activations']);
        $this->assertSame('creator',$get($sold)['relationship']);
        $this->reject(fn()=>$get($foreign),'license_id');
        $json=json_encode([$row,$list]);
        foreach (['SECRET-KEY','SECRET-PREFIX','private.example.test','key_hash','key_ciphertext','pending_domain','storage_path','user_id'] as $secret)
            $this->assertStringNotContainsString($secret,$json);
        $this->assertSame($before,DB::table('product_licenses')->orderBy('id')->get()->toJson());
        $held->entitlement->update(['status'=>'revoked','revoked_at'=>now()]);
        $this->assertFalse($get($held)['installation_active']);
    }

    public function test_inconsistent_license_links_are_not_exposed(): void
    {
        $a=$this->actor(); $b=$this->actor(); $c=$this->actor();
        $wrongHolder=$this->licenseFixture($a,$b);
        $wrongHolder->entitlement->update(['user_id'=>$c->id]);
        $wrongProduct=$this->licenseFixture($a,$b);
        $wrongProduct->version->update(['marketplace_listing_id'=>$this->listing($c)->id]);
        $wrongVersion=$this->licenseFixture($a,$b);
        $other=$this->licenseFixture($c,$b);
        $wrongVersion->update(['product_file_version_id'=>$other->product_file_version_id]);
        $s=new CommerceReadTools();
        foreach ([$wrongHolder,$wrongProduct,$wrongVersion] as $record) {
            foreach ([$a,$b] as $actor)
                $this->reject(fn()=>$s->call('bambodia_license_get',['license_id'=>$record->public_id],$actor,true),'license_id');
        }
        $this->assertSame(0,$s->call('bambodia_licenses_list',[],$a,true)['total']);
        $this->assertSame(1,$s->call('bambodia_licenses_list',[],$b,true)['total']);
    }

    public function test_pagination_and_completion_window_are_bounded(): void
    {
        $a=$this->actor(); $b=$this->actor();
        for ($i=0;$i<21;$i++) $this->orderFixture($a,$b,'awaiting_payment');
        $old=$this->orderFixture($a,$b);
        $old->update(['completed_at'=>now()->subDays(8)]);
        $this->paid($old);
        $pending=$this->orderFixture($a,$b,'awaiting_payment');
        $this->paid($pending);
        $s=new CommerceReadTools();
        $first=$s->call('bambodia_orders_list',['page'=>1],$a,true);
        $second=$s->call('bambodia_orders_list',['page'=>2],$a,true);
        $this->assertSame(23,$first['total']);
        $this->assertCount(20,$first['data']);
        $this->assertCount(3,$second['data']);
        $this->assertEmpty(array_intersect(array_column($first['data'],'reference'),array_column($second['data'],'reference')));
        $this->assertSame([],$s->call('bambodia_transactions_summary',[],$a,true)['by_currency_and_status']);
        $this->assertSame(1,$s->call('bambodia_transactions_summary',['days'=>9],$a,true)['by_currency_and_status'][0]['orders']);
        $this->reject(fn()=>$s->call('bambodia_receipt_get',['reference'=>$pending->reference()],$a,true),'reference');
    }

    public function test_stage_adds_only_six_read_definitions(): void
    {
        $p=new Process(['python3',base_path('support/bambodia-mcp-stage-commerce-read.py'),'--self-test']);
        $p->mustRun();
        $this->assertStringContainsString('PASS: exact additive READ integration',$p->getOutput());
    }
}
