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
use App\Support\Mcp\ProjectWriteTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class McpProjectWriteToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.enabled'=>true,'mcp.production_writes_enabled'=>true]);
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

    private function trackingArgs(MarketplaceOrder $order,User $actor,array $args=[]): array
    {
        $r=(new ProjectReadTools())->call('bambodia_tracking_get',['reference'=>$order->reference()],$actor,true);
        return ['reference'=>$order->reference(),'request_id'=>(string)Str::uuid(),'expected_version'=>$r['version'],'confirm'=>true,...$args];
    }

    private function contractArgs(FreelanceContract $contract,User $actor,array $args=[]): array
    {
        $r=(new ProjectReadTools())->call('bambodia_milestones_list',['contract_id'=>$contract->public_id],$actor,true);
        return ['contract_id'=>$contract->public_id,'request_id'=>(string)Str::uuid(),'expected_version'=>$r['version'],'confirm'=>true,
            'title'=>'New milestone fixture','description'=>'A complete and verifiable milestone for this disposable draft.',
            'amount'=>'0.00',...$args];
    }

    public function test_registry_gates_and_strict_confirmation(): void
    {
        $a=$this->actor(); $b=$this->actor(); $staff=$this->actor('staff');
        $o=$this->order($b,$a);
        $args=$this->trackingArgs($o,$a,['title'=>'New checklist task']);
        $s=new ProjectWriteTools();
        $this->assertCount(4,$s->definitions(true));
        $this->assertSame([],$s->definitions(false));
        foreach ($s->definitions(true) as $d) {
            $this->assertFalse($d['annotations']['readOnlyHint']);
            $this->assertTrue($d['annotations']['idempotentHint']);
            $this->assertFalse($d['inputSchema']['additionalProperties']);
            $this->reject(fn()=>$s->call($d['name'],[],$a,false,true),'access');
            $this->reject(fn()=>$s->call($d['name'],[],$a,true,false),'access');
        }
        $name='bambodia_tracking_task_create';
        foreach ([['confirm'=>1],['confirm'=>'true'],['confirm'=>false],['confirm'=>null]] as $bad)
            $this->reject(fn()=>$s->call($name,[...$args,...$bad],$a,true,true),'confirm');
        $this->reject(fn()=>$s->call($name,[...$args,'recipient_id'=>$b->id],$a,true,true),'arguments');
        $this->reject(fn()=>$s->call($name,[...$args,'title'=>'<b>Task</b>'],$a,true,true),'title');
        $this->reject(fn()=>$s->call($name,[...$args,'request_id'=>'wrong'],$a,true,true),'request_id');
        $this->reject(fn()=>$s->call($name,$args,$staff,true,true),'access');
        config(['mcp.production_writes_enabled'=>false]);
        $this->reject(fn()=>$s->call($name,$args,$a,true,true),'access');
        config(['mcp.production_writes_enabled'=>true]);
        $a->update(['account_status'=>'deactivated']);
        $this->reject(fn()=>$s->call($name,$args,$a,true,true),'access');
        $this->assertDatabaseCount('order_tracking_tasks',0);
        $this->assertDatabaseCount('mcp_project_operations',0);
    }

    public function test_task_creation_update_replay_conflict_and_audit(): void
    {
        $a=$this->actor(); $b=$this->actor(); $c=$this->actor();
        $order=$this->order($b,$a);
        $other=$this->order($b,$a);
        $s=new ProjectWriteTools();
        $args=$this->trackingArgs($order,$a,['title'=>'Prepare prototype']);
        $before=DB::table('audit_events')->count();
        $r=$s->call('bambodia_tracking_task_create',$args,$a,true,true);
        $this->assertFalse($r['replayed']);
        $this->assertDatabaseHas('order_tracking_tasks',['id'=>$r['task_id'],'marketplace_order_id'=>$order->id,'created_by'=>$a->id,'status'=>'planned']);
        $this->assertDatabaseHas('audit_events',['id'=>$r['audit_id'],'actor_id'=>$a->id,'event'=>'mcp.phase4.tracking_task_create']);
        $this->assertDatabaseHas('mcp_project_operations',['actor_id'=>$a->id,'request_id'=>$args['request_id'],'audit_event_id'=>$r['audit_id']]);
        $this->assertDatabaseHas('account_notifications',['user_id'=>$b->id,'event'=>'order.tracking.task_added']);
        $this->assertSame([...$r,'replayed'=>true],$s->call('bambodia_tracking_task_create',$args,$a,true,true));
        $this->assertDatabaseCount('order_tracking_tasks',1);
        $this->assertDatabaseCount('mcp_project_operations',1);
        $this->assertSame($before+2,DB::table('audit_events')->count());
        $this->reject(fn()=>$s->call('bambodia_tracking_task_create',[...$args,'title'=>'Different task'],$a,true,true),'request_id');
        $this->reject(fn()=>$s->call('bambodia_tracking_task_create',[...$args,'request_id'=>(string)Str::uuid()],$a,true,true),'expected_version');
        $this->reject(fn()=>$s->call('bambodia_tracking_task_create',$args,$b,true,true),'reference');
        $this->reject(fn()=>$s->call('bambodia_tracking_task_create',$args,$c,true,true),'reference');
        $update=$this->trackingArgs($order,$a,['task_id'=>$r['task_id'],'status'=>'completed']);
        $u=$s->call('bambodia_tracking_task_update',$update,$a,true,true);
        $this->assertSame('completed',$u['status']);
        $this->assertDatabaseHas('order_tracking_tasks',['id'=>$r['task_id'],'status'=>'completed']);
        $this->assertNotNull(DB::table('order_tracking_tasks')->where('id',$r['task_id'])->value('completed_at'));
        $this->assertSame([...$u,'replayed'=>true],$s->call('bambodia_tracking_task_update',$update,$a,true,true));
        $wrong=$this->trackingArgs($other,$a,['task_id'=>$r['task_id'],'status'=>'planned']);
        $this->reject(fn()=>$s->call('bambodia_tracking_task_update',$wrong,$a,true,true),'task_id');
        $this->assertDatabaseCount('mcp_project_operations',2);
        $this->assertSame(2,DB::table('account_notifications')->where('user_id',$b->id)->whereIn('event',['order.tracking.task_added','order.tracking.task_updated'])->count());
    }

    public function test_tracking_rejects_unverified_orders_frozen_tasks_and_full_checklist(): void
    {
        $a=$this->actor(); $b=$this->actor(); $c=$this->actor();
        $s=new ProjectWriteTools();
        $valid=$this->order($b,$a);
        $args=$this->trackingArgs($valid,$a,['title'=>'Prepare prototype']);
        foreach ([$this->order($b,$a,'project','completed',false),$this->order($b,$a,'project','completed',true,false),
            $this->order($b,$a,'project','refunded'),$this->order($b,$a,'product'),$this->order($b,$c)] as $bad)
            $this->reject(fn()=>$s->call('bambodia_tracking_task_create',[...$args,'reference'=>$bad->reference()],$a,true,true),'reference');
        $valid->serviceInquiry()->update(['client_id'=>$c->id]);
        $this->reject(fn()=>$s->call('bambodia_tracking_task_create',$args,$a,true,true),'reference');
        $valid->serviceInquiry()->update(['client_id'=>$b->id,'delivery_status'=>'delivered']);
        $this->reject(fn()=>$s->call('bambodia_tracking_task_create',$this->trackingArgs($valid,$a,['title'=>'Prepare prototype']),$a,true,true),'reference');
        $valid->serviceInquiry()->update(['delivery_status'=>'accepted']);
        $this->reject(fn()=>$s->call('bambodia_tracking_task_create',$this->trackingArgs($valid,$a,['title'=>'Prepare prototype']),$a,true,true),'reference');
        $valid->serviceInquiry()->update(['delivery_status'=>'in_progress']);
        for ($i=1;$i<=20;$i++) DB::table('order_tracking_tasks')->insert(['marketplace_order_id'=>$valid->id,'created_by'=>$a->id,
            'title'=>'Fixture '.$i,'position'=>$i,'status'=>'planned','created_at'=>now(),'updated_at'=>now()]);
        $this->reject(fn()=>$s->call('bambodia_tracking_task_create',$this->trackingArgs($valid,$a,['title'=>'Over limit task']),$a,true,true),'reference');
        $this->assertDatabaseCount('mcp_project_operations',0);
        $this->assertDatabaseCount('order_tracking_tasks',20);
    }

    public function test_milestone_requires_client_draft_budget_version_and_idempotency(): void
    {
        $a=$this->actor(); $b=$this->actor(); $c=$this->actor();
        $draft=$this->contract($a,$b); $draft->update(['agreed_budget'=>'150.00']);
        $s=new ProjectWriteTools();
        $args=$this->contractArgs($draft,$a,['amount'=>'50.00']);
        $r=$s->call('bambodia_contract_milestone_create',$args,$a,true,true);
        $this->assertSame(2,$r['sequence']);
        $this->assertDatabaseHas('contract_milestones',['id'=>$r['milestone_id'],'freelance_contract_id'=>$draft->id,'status'=>'planned']);
        $this->assertSame([...$r,'replayed'=>true],$s->call('bambodia_contract_milestone_create',$args,$a,true,true));
        $this->reject(fn()=>$s->call('bambodia_contract_milestone_create',$args,$b,true,true),'contract_id');
        $this->reject(fn()=>$s->call('bambodia_contract_milestone_create',$args,$c,true,true),'contract_id');
        $this->reject(fn()=>$s->call('bambodia_contract_milestone_create',[...$args,'request_id'=>(string)Str::uuid()],$a,true,true),'expected_version');
        $this->reject(fn()=>$s->call('bambodia_contract_milestone_create',$this->contractArgs($draft,$a,['amount'=>'0.01']),$a,true,true),'amount');
        $draft->update(['status'=>'offered']);
        $this->reject(fn()=>$s->call('bambodia_contract_milestone_create',$this->contractArgs($draft,$a),$a,true,true),'contract_id');
        $this->assertSame(2,$draft->milestones()->count());
        $this->assertDatabaseCount('mcp_project_operations',1);
    }

    public function test_comment_is_confirmed_plain_text_once_and_never_leaks_body_in_receipts(): void
    {
        $a=$this->actor(); $b=$this->actor(); $c=$this->actor();
        $o=$this->order($a,$b);
        $s=new ProjectWriteTools();
        $args=$this->trackingArgs($o,$a,['message'=>'PRIVATE_COMMENT accepted for testing.']);
        $name='bambodia_tracking_comment_create';
        $this->reject(fn()=>$s->call($name,[...$args,'confirm'=>false],$a,true,true),'confirm');
        $this->reject(fn()=>$s->call($name,[...$args,'message'=>'<script>unsafe</script>'],$a,true,true),'message');
        $this->reject(fn()=>$s->call($name,[...$args,'message'=>'https://example.test/'],$a,true,true),'message');
        $this->reject(fn()=>$s->call($name,$args,$c,true,true),'reference');
        $r=$s->call($name,$args,$a,true,true);
        $this->assertSame('sent',$r['status']);
        $this->assertDatabaseHas('service_inquiry_messages',['id'=>$r['message_id'],'sender_id'=>$a->id,
            'body'=>$args['message'],'content_format'=>'plain']);
        $this->assertSame([...$r,'replayed'=>true],$s->call($name,$args,$a,true,true));
        $this->assertDatabaseCount('service_inquiry_messages',1);
        $this->assertSame(1,DB::table('account_notifications')->where('user_id',$b->id)->where('event','inquiry.replied')->count());
        $this->assertStringNotContainsString('PRIVATE_COMMENT',json_encode($r));
        $this->assertStringNotContainsString('PRIVATE_COMMENT',DB::table('mcp_project_operations')->sole()->result);
        $this->assertStringNotContainsString('PRIVATE_COMMENT',DB::table('account_notifications')->get()->toJson());
        $o->serviceInquiry()->update(['status'=>'closed']);
        $this->reject(fn()=>$s->call($name,$this->trackingArgs($o,$b,['message'=>'Second comment']),$b,true,true),'reference');
        $this->assertDatabaseCount('service_inquiry_messages',1);
        $this->assertDatabaseCount('mcp_project_operations',1);
    }

    public function test_failed_mutation_rolls_back_domain_notification_and_ledger(): void
    {
        $a=$this->actor(); $b=$this->actor();
        $o=$this->order($b,$a);
        $args=$this->trackingArgs($o,$a,['title'=>'Transactional fixture']);
        $before=DB::table('audit_events')->count();
        // Force a late exception at the audit write, after task and notification creation.
        \App\Models\AuditEvent::creating(function($event) {
            if ($event->event==='mcp.phase4.tracking_task_create') throw new \RuntimeException('Fixture rollback');
        });
        try {
            (new ProjectWriteTools())->call('bambodia_tracking_task_create',$args,$a,true,true);
            $this->fail('Expected rollback');
        } catch (\RuntimeException $e) { $this->assertSame('Fixture rollback',$e->getMessage()); }
        finally { \App\Models\AuditEvent::flushEventListeners(); }
        $this->assertDatabaseCount('order_tracking_tasks',0);
        $this->assertDatabaseCount('mcp_project_operations',0);
        $this->assertSame($before,DB::table('audit_events')->count());
        $this->assertSame(0,DB::table('account_notifications')->where('event','order.tracking.task_added')->count());
    }

    public function test_completed_request_can_replay_after_due_date(): void
    {
        $a=$this->actor(); $b=$this->actor();
        $order=$this->order($b,$a);
        $s=new ProjectWriteTools();
        $args=$this->trackingArgs($order,$a,['title'=>'Dated checklist fixture','due_on'=>now()->toDateString()]);
        $r=$s->call('bambodia_tracking_task_create',$args,$a,true,true);
        $this->travel(2)->days();
        try {
            $this->assertSame([...$r,'replayed'=>true],$s->call('bambodia_tracking_task_create',$args,$a,true,true));
            $new=$this->trackingArgs($order,$a,['title'=>'Expired date fixture','due_on'=>$args['due_on']]);
            $this->reject(fn()=>$s->call('bambodia_tracking_task_create',$new,$a,true,true),'due_on');
            $this->assertDatabaseCount('order_tracking_tasks',1);
            $this->assertDatabaseCount('mcp_project_operations',1);
        } finally { $this->travelBack(); }
    }

    public function test_staging_preserves_thirty_four_prior_definitions_and_write_gates(): void
    {
        $p=new Process(['python3',base_path('support/bambodia-mcp-stage-project-write.py'),'--self-test']);
        $p->mustRun();
        $this->assertStringContainsString('PASS: additive four-tool Project WRITE integration',$p->getOutput());
    }
}
