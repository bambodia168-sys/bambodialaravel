<?php

namespace Tests\Feature;

use App\Models\CommercePaymentMethod;
use App\Models\FreelanceContract;
use App\Models\JobProposal;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\User;
use App\Support\Mcp\ProjectReadTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class McpProjectReadToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.enabled'=>true]);
        Http::preventStrayRequests();
    }

    private function actor(string $role='super_admin'): User
    {
        return User::query()->create(['name'=>'PRIVATE_PERSON','email'=>Str::uuid().'@example.test',
            'password'=>'LongExamplePassw0rd!','role'=>$role]);
    }

    private function order(User $buyer,User $seller,string $type='project',string $status='completed',bool $live=true,bool $settled=true): MarketplaceOrder
    {
        $listing=MarketplaceListing::query()->create(['user_id'=>$seller->id,'type'=>$type,'title'=>'Project fixture',
            'description'=>'PRIVATE_LISTING_DESCRIPTION','status'=>'published','price'=>'85.00','currency'=>'USD']);
        $method=CommercePaymentMethod::query()->firstOrCreate(['name'=>'Project fixture transfer'],['available'=>true]);
        $order=MarketplaceOrder::query()->create(['number'=>(string)Str::uuid(),'request_key'=>(string)Str::uuid(),
            'buyer_id'=>$buyer->id,'seller_id'=>$seller->id,'marketplace_listing_id'=>$listing->id,'listing_title'=>$listing->title,
            'buyer_name'=>'PRIVATE_BUYER','seller_name'=>'PRIVATE_SELLER','total'=>'85.00','currency'=>'USD',
            'status'=>$status,'completed_at'=>now()]);
        $order->payments()->create(['number'=>(string)Str::uuid(),'commerce_payment_method_id'=>$method->id,
            'method_name'=>$method->name,'provider'=>'bank_transfer','status'=>'paid','live_mode'=>$live,
            'settlement_reference'=>$settled?'SECRET-'.Str::uuid():null]);
        $order->serviceInquiry()->create(['marketplace_listing_id'=>$listing->id,'client_id'=>$buyer->id,
            'creator_id'=>$seller->id,'subject'=>'PRIVATE_SUBJECT','status'=>'open','currency'=>'USD']);
        return $order;
    }

    private function contract(User $client,User $freelancer,string $status='draft'): FreelanceContract
    {
        $job=MarketplaceJob::query()->create(['client_id'=>$client->id,'title'=>'Job fixture','description'=>'PRIVATE_JOB',
            'category'=>'development','budget'=>'100.00','status'=>'published']);
        $proposal=JobProposal::query()->create(['marketplace_job_id'=>$job->id,'freelancer_id'=>$freelancer->id,
            'cover_message'=>'PRIVATE_PROPOSAL','proposed_budget'=>'100.00','status'=>'shortlisted']);
        $contract=FreelanceContract::query()->create(['job_proposal_id'=>$proposal->id,'client_id'=>$client->id,
            'freelancer_id'=>$freelancer->id,'title'=>'Contract fixture','scope'=>'PRIVATE_SCOPE','terms'=>'PRIVATE_TERMS',
            'agreed_budget'=>'100.00','currency'=>'USD','status'=>$status,'terms_sha256'=>str_repeat('a',64)]);
        $contract->milestones()->create(['sequence'=>1,'title'=>'Milestone fixture','description'=>'PRIVATE_MILESTONE_DESCRIPTION',
            'amount'=>'100.00','status'=>$status==='active'?'accepted':'planned','due_on'=>now()->addDay(),
            'accepted_at'=>$status==='active'?now():null]);
        return $contract;
    }

    private function reject(callable $fn,string $field): void
    {
        try { $fn(); $this->fail('Expected rejection: '.$field); }
        catch (ValidationException $e) { $this->assertArrayHasKey($field,$e->errors()); }
    }

    public function test_registry_scope_fresh_actor_and_strict_arguments(): void
    {
        $a=$this->actor(); $staff=$this->actor('staff'); $inactive=$this->actor();
        $inactive->update(['account_status'=>'deactivated']);
        $s=new ProjectReadTools();
        $defs=$s->definitions();
        $this->assertCount(4,$defs);
        $this->assertSame(array_keys(ProjectReadTools::TOOLS),array_column($defs,'name'));
        foreach ($defs as $d) {
            $this->assertTrue($d['annotations']['readOnlyHint']);
            $this->assertFalse($d['inputSchema']['additionalProperties']);
            foreach ([[$a,false],[$staff,true],[$inactive,true]] as [$owner,$read])
                $this->reject(fn()=>$s->call($d['name'],[],$owner,$read),'access');
        }
        foreach ([
            ['bambodia_tracking_list',['page'=>'1'],'page'],['bambodia_tracking_list',['page'=>1001],'page'],
            ['bambodia_tracking_list',['buyer_id'=>1],'arguments'],['bambodia_tracking_list',['type'=>'product'],'type'],
            ['bambodia_tracking_list',['status'=>'completed'],'status'],['bambodia_tracking_list',['direction'=>'all'],'direction'],
            ['bambodia_tracking_get',['reference'=>1234567890],'reference'],['bambodia_tracking_get',['reference'=>'0123456789'],'reference'],
            ['bambodia_contracts_list',['role'=>'admin'],'role'],['bambodia_contracts_list',['status'=>'paid'],'status'],
            ['bambodia_milestones_list',['contract_id'=>123456789],'contract_id'],
            ['bambodia_milestones_list',['contract_id'=>'123456789','include_terms'=>true],'arguments'],
        ] as [$name,$args,$field]) $this->reject(fn()=>$s->call($name,$args,$a,true),$field);
        config(['commerce.enabled'=>false]);
        foreach (['bambodia_tracking_list','bambodia_tracking_get'] as $name)
            $this->reject(fn()=>$s->call($name,[],$a,true),'access');
        $this->assertSame(0,$s->call('bambodia_contracts_list',[],$a,true)['total']);
        User::query()->whereKey($a->id)->update(['role'=>'staff']);
        $this->reject(fn()=>$s->call('bambodia_contracts_list',[],$a,true),'access');
    }

    public function test_tracking_is_participant_scoped_live_paid_and_inquiry_consistent(): void
    {
        $a=$this->actor(); $b=$this->actor(); $c=$this->actor();
        $own=$this->order($a,$b); $sold=$this->order($b,$a,'service');
        $foreign=$this->order($b,$c);
        $test=$this->order($a,$b,'project','completed',false);
        $unsettled=$this->order($a,$b,'project','completed',true,false);
        $refunded=$this->order($a,$b,'project','refunded');
        $pending=$this->order($a,$b,'project','awaiting_payment');
        $product=$this->order($a,$b,'product');
        $missing=$this->order($a,$b); $missing->serviceInquiry()->delete();
        $badClient=$this->order($a,$b); $badClient->serviceInquiry()->update(['client_id'=>$c->id]);
        $badCreator=$this->order($a,$b); $badCreator->serviceInquiry()->update(['creator_id'=>$c->id]);
        $badListing=$this->order($a,$b); $badListing->serviceInquiry()->update(['marketplace_listing_id'=>$sold->marketplace_listing_id]);
        $s=new ProjectReadTools();
        $this->assertSame(2,$s->call('bambodia_tracking_list',[],$a,true)['total']);
        $this->assertSame(1,$s->call('bambodia_tracking_list',['direction'=>'buyer'],$a,true)['total']);
        $this->assertSame(1,$s->call('bambodia_tracking_list',['direction'=>'seller','type'=>'service'],$a,true)['total']);
        $this->assertSame(2,$s->call('bambodia_tracking_list',['status'=>'awaiting_requirements'],$a,true)['total']);
        foreach ([$foreign,$test,$unsettled,$refunded,$pending,$product,$missing,$badClient,$badCreator,$badListing] as $o)
            $this->reject(fn()=>$s->call('bambodia_tracking_get',['reference'=>$o->reference()],$a,true),'reference');
        $this->assertSame('buyer',$s->call('bambodia_tracking_get',['reference'=>$own->reference()],$a,true)['relationship']);
        $this->assertSame('seller',$s->call('bambodia_tracking_get',['reference'=>$sold->reference()],$a,true)['relationship']);
    }

    public function test_tracking_details_show_only_owned_tasks_and_safe_progress_metadata_without_writes(): void
    {
        $a=$this->actor(); $b=$this->actor();
        $order=$this->order($a,$b); $other=$this->order($a,$b);
        $inquiry=$order->serviceInquiry()->firstOrFail();
        $inquiry->update(['delivery_status'=>'in_progress']);
        $inquiry->messages()->create(['sender_id'=>$b->id,'body'=>'PRIVATE_CHAT']);
        foreach ([$order,$other] as $o) {
            DB::table('order_tracking_tasks')->insert(['marketplace_order_id'=>$o->id,'created_by'=>$b->id,
                'title'=>$o->id===$order->id?'Visible task':'OTHER_TASK','position'=>1,'status'=>'completed','completed_at'=>now(),
                'created_at'=>now(),'updated_at'=>now()]);
        }
        DB::table('order_tracking_tasks')->insert(['marketplace_order_id'=>$order->id,'created_by'=>$b->id,
            'title'=>'Planned task','position'=>2,'status'=>'planned','created_at'=>now(),'updated_at'=>now()]);
        DB::table('order_tracking_extension_requests')->insert(['marketplace_order_id'=>$order->id,'requested_by'=>$b->id,
            'proposed_due_on'=>now()->addDays(3)->toDateString(),'reason'=>'PRIVATE_EXTENSION_REASON','status'=>'approved',
            'responded_by'=>$a->id,'responded_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
        $tables=['marketplace_orders','commerce_payments','service_inquiries','service_inquiry_messages',
            'order_tracking_tasks','order_tracking_extension_requests','account_notifications','audit_events'];
        $before=$this->snapshot($tables);
        $s=new ProjectReadTools();
        $r=$s->call('bambodia_tracking_get',['reference'=>$order->reference()],$a,true);
        $this->assertSame(2,$r['task_count']);
        $this->assertSame(1,$r['completed_task_count']);
        $this->assertSame(53,$r['delivery_progress_percent']);
        $this->assertFalse($r['tasks_truncated']);
        $this->assertFalse($r['pending_extension']);
        $this->assertSame(now()->addDays(3)->toDateString(),$r['approved_due_on']);
        $this->assertSame(['task_id','position','title','status','due_on','completed_at'],array_keys($r['tasks'][0]));
        $this->assertSame(['reference','listing_title','listing_type','relationship','delivery_status','verified_live_payment','completed_at',
            'task_count','completed_task_count','tasks_truncated','tasks','delivery_progress_percent','progress_basis','approved_due_on','pending_extension'],array_keys($r));
        $json=json_encode($r);
        foreach (['PRIVATE_CHAT','PRIVATE_EXTENSION_REASON','PRIVATE_BUYER','PRIVATE_SELLER','PRIVATE_SUBJECT','OTHER_TASK','settlement_reference','created_by'] as $secret)
            $this->assertStringNotContainsString($secret,$json);
        $this->assertSame($before,$this->snapshot($tables));
    }

    public function test_contract_and_milestone_privacy_including_unsent_drafts(): void
    {
        $a=$this->actor(); $b=$this->actor(); $c=$this->actor();
        $draft=$this->contract($a,$b); $incoming=$this->contract($b,$a);
        $active=$this->contract($b,$a,'active'); $foreign=$this->contract($b,$c,'offered');
        $tables=['freelance_contracts','contract_milestones','job_proposals','contract_consents','contract_messages',
            'milestone_submissions','milestone_reviews','account_notifications','audit_events'];
        $before=$this->snapshot($tables);
        $s=new ProjectReadTools();
        $list=$s->call('bambodia_contracts_list',[],$a,true);
        $this->assertSame(2,$list['total']);
        $this->assertSame(1,$s->call('bambodia_contracts_list',['role'=>'client','status'=>'draft'],$a,true)['total']);
        $this->assertSame(1,$s->call('bambodia_contracts_list',['role'=>'freelancer'],$a,true)['total']);
        $this->assertSame(0,$s->call('bambodia_contracts_list',['role'=>'freelancer','status'=>'draft'],$a,true)['total']);
        foreach ([$incoming,$foreign] as $o) $this->reject(fn()=>$s->call('bambodia_milestones_list',['contract_id'=>$o->public_id],$a,true),'contract_id');
        $this->reject(fn()=>$s->call('bambodia_milestones_list',['contract_id'=>$draft->public_id],$b,true),'contract_id');
        $r=$s->call('bambodia_milestones_list',['contract_id'=>$active->public_id],$a,true);
        $this->assertSame('freelancer',$r['contract']['relationship']);
        $this->assertSame(1,$r['contract']['accepted_milestone_count']);
        $this->assertSame(1,$r['total']);
        $this->assertSame(['milestone_id','sequence','title','amount','due_on','status','accepted_at'],array_keys($r['data'][0]));
        $this->assertSame(['contract_id','title','relationship','status','currency','agreed_budget','milestone_count',
            'accepted_milestone_count','offered_at','accepted_at','completed_at','cancelled_at'],array_keys($r['contract']));
        $this->assertSame('client',$s->call('bambodia_milestones_list',['contract_id'=>$draft->public_id],$a,true)['contract']['relationship']);
        $json=json_encode([$r,$list]);
        foreach (['PRIVATE_SCOPE','PRIVATE_TERMS','PRIVATE_PROPOSAL','PRIVATE_MILESTONE_DESCRIPTION','terms_sha256','client_id','freelancer_id','evidence_url'] as $secret)
            $this->assertStringNotContainsString($secret,$json);
        $this->assertSame($before,$this->snapshot($tables));
    }

    private function snapshot(array $tables): array
    {
        return array_map(fn ($t)=>DB::table($t)->orderBy('id')->get()->toJson(),$tables);
    }

    public function test_staging_preserves_thirty_prior_definitions(): void
    {
        $p=new Process(['python3',base_path('support/bambodia-mcp-stage-project-read.py'),'--self-test']);
        $p->mustRun();
        $this->assertStringContainsString('PASS: additive four-tool Project READ integration',$p->getOutput());
    }
}
