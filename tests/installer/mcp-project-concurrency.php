<?php
// Isolated CI-only acceptance. Never run against a deployment or non-disposable database.
use App\Models\AuditEvent;
use App\Models\CommercePaymentMethod;
use App\Models\FreelanceContract;
use App\Models\JobProposal;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOrder;
use App\Models\User;
use App\Support\Mcp\ProjectReadTools;
use App\Support\Mcp\ProjectWriteTools;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

if (getenv('GITHUB_ACTIONS')!=='true' || getenv('RUN_MCP_PROJECT_CONCURRENCY')!=='1'
    || getenv('APP_ENV')!=='testing' || getenv('DB_CONNECTION')!=='mysql'
    || getenv('DB_HOST')!=='127.0.0.1' || getenv('DB_DATABASE')!=='bambodia_acceptance'
    || getenv('DB_USERNAME')!=='bambodia_acceptance' || getenv('DB_URL')!==''
    || !getenv('APP_KEY')) {
    fwrite(STDERR,"Refusing non-isolated concurrency execution\n"); exit(2);
}
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
// Laravel's console exception renderer may exit zero for a standalone script.
// Override the uncaught handler after bootstrap so parent and workers fail closed.
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR,'MCP_PROJECT_CONCURRENCY=FAIL '.get_class($e).': '.$e->getMessage().PHP_EOL);
    exit(1);
});
if (!$app->environment('testing') || DB::connection()->getDriverName()!=='mysql'
    || DB::connection()->getDatabaseName()!=='bambodia_acceptance'
    || config('database.connections.mysql.host')!=='127.0.0.1'
    || config('database.connections.mysql.username')!=='bambodia_acceptance'
    || config('database.connections.mysql.url')) {
    fwrite(STDERR,"Effective database isolation guard failed\n"); exit(2);
}
config(['commerce.enabled'=>true,'mcp.production_writes_enabled'=>true,'mail.default'=>'array','queue.default'=>'sync']);
Http::preventStrayRequests();
DB::statement('SET SESSION innodb_lock_wait_timeout = 20');
function check(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); }
function signalLine(string $tag,mixed $data): void {
    echo $tag.' '.json_encode($data,JSON_THROW_ON_ERROR).PHP_EOL;
    flush();
}
function awaitCondition(callable $condition,string $label,float $seconds=12): void {
    $end=microtime(true)+$seconds;
    do { if ($condition()) return; usleep(25000); } while (microtime(true)<$end);
    throw new RuntimeException('Timeout: '.$label);
}

if (($argv[1]??'')==='--worker') {
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $actor=User::query()->findOrFail($input['actor']);
    $conn=(int)DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
    if (isset($input['fault_gate'])) {
        AuditEvent::creating(function($event) use ($input) {
            if ($event->event!=='mcp.phase4.tracking_comment_create') return;
            signalLine('FAULT_READY',true);
            awaitCondition(fn()=>is_file($input['fault_gate']),'rollback gate',18);
            throw new RuntimeException('CI injected late audit failure');
        });
    }
    signalLine('READY',$conn);
    try {
        $result=(new ProjectWriteTools())->call($input['tool'],$input['args'],$actor,true,true);
        signalLine('RESULT',['kind'=>'ok','receipt'=>$result]);
    } catch (ValidationException $e) {
        signalLine('RESULT',['kind'=>'rejected','fields'=>array_keys($e->errors())]);
    } catch (RuntimeException $e) {
        if ($e->getMessage()!=='CI injected late audit failure') throw $e;
        signalLine('RESULT',['kind'=>'rolled_back']);
    }
    exit(0);
}

final class ConcurrentProjectAcceptance
{
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


    private array $workers=[];

    private function worker(User $actor,string $tool,array $args,?string $gate=null): Process
    {
        $input=['actor'=>$actor->id,'tool'=>$tool,'args'=>$args];
        if ($gate!==null) $input['fault_gate']=$gate;
        $p=new Process([PHP_BINARY,__FILE__,'--worker'],dirname(__DIR__,2),null,json_encode($input,JSON_THROW_ON_ERROR),55);
        $this->workers[]=$p;
        $p->start();
        return $p;
    }

    private function line(Process $p,string $tag): mixed
    {
        if (preg_match('/^'.preg_quote($tag,'/').' (.+)$/m',$p->getOutput(),$m))
            return json_decode($m[1],true,512,JSON_THROW_ON_ERROR);
        return null;
    }

    private function ready(Process $p): int
    {
        awaitCondition(function()use($p) {
            check($p->isRunning() || $this->line($p,'READY')!==null,'Worker exited before readiness');
            return $this->line($p,'READY')!==null;
        },'worker ready');
        return (int)$this->line($p,'READY');
    }

    private function blocked(array $ids): void
    {
        // SHOW PROCESSLIST truncates Info at 100 characters, which can hide
        // FOR UPDATE on longer order/contract queries. FULL preserves it.
        awaitCondition(function()use($ids) {
            $seen=[];
            foreach (DB::select('SHOW FULL PROCESSLIST') as $row) {
                $id=(int)$row->Id;
                if (in_array($id,$ids,true) && stripos((string)$row->Info,'for update')!==false) $seen[]=$id;
            }
            return count(array_unique($seen))===count($ids);
        },'independent MySQL sessions executing locking queries');
        check(count(array_unique($ids))===count($ids),'Workers share connection');
        check(!in_array((int)DB::selectOne('SELECT CONNECTION_ID() AS id')->id,$ids,true),'Worker shares parent connection');
    }

    private function result(Process $p): array
    {
        $p->wait();
        check($p->isSuccessful(),'Worker failed: '.substr($p->getErrorOutput(),0,500));
        $r=$this->line($p,'RESULT');
        check(is_array($r),'Missing worker result');
        return $r;
    }

    private function race(string $table,int $id,User $a,User $b,string $tool,array $first,array $second): array
    {
        DB::beginTransaction();
        try {
            check(DB::table($table)->where('id',$id)->lockForUpdate()->first()!==null,'Missing parent lock fixture');
            $p=$this->worker($a,$tool,$first);
            $q=$this->worker($b,$tool,$second);
            $this->blocked([$this->ready($p),$this->ready($q)]);
        } finally { DB::rollBack(); }
        return [$this->result($p),$this->result($q)];
    }

    private function exactReplay(array $r): array
    {
        check($r[0]['kind']==='ok'&&$r[1]['kind']==='ok','Expected both successful receipts');
        $first=$r[0]['receipt']; $second=$r[1]['receipt'];
        check($first['replayed']!==$second['replayed'],'Expected exactly one replay');
        unset($first['replayed'],$second['replayed']);
        check($first===$second,'Replay receipt differs');
        return $first;
    }

    private function oneStale(array $r): array
    {
        $ok=array_values(array_filter($r,fn($v)=>$v['kind']==='ok'));
        $bad=array_values(array_filter($r,fn($v)=>$v['kind']==='rejected'));
        check(count($ok)===1&&count($bad)===1&&$bad[0]['fields']===['expected_version'],'Expected one write and one stale rejection');
        check($ok[0]['receipt']['replayed']===false,'Unexpected replay');
        return $ok[0]['receipt'];
    }

    private function ledger(User $actor,array $receipt): void
    {
        check(DB::table('mcp_project_operations')->where('actor_id',$actor->id)->where('request_id',$receipt['request_id'])
            ->where('audit_event_id',$receipt['audit_id'])->count()===1,'Ledger missing or duplicated');
        check(DB::table('audit_events')->where('id',$receipt['audit_id'])->where('actor_id',$actor->id)->count()===1,'Linked audit missing');
    }

    public function run(): void
    {
        $a=$this->actor(); $b=$this->actor();
        $order=$this->order($b,$a);
        $task='bambodia_tracking_task_create';
        $args=$this->trackingArgs($order,$a,['title'=>'Concurrent task fixture']);
        $r=$this->exactReplay($this->race('users',$a->id,$a,$a,$task,$args,$args));
        $this->ledger($a,$r);
        check(DB::table('order_tracking_tasks')->where('marketplace_order_id',$order->id)->count()===1,'Duplicate task');
        check(DB::table('account_notifications')->where('user_id',$b->id)->where('event','order.tracking.task_added')->count()===1,'Duplicate task notification');
        echo "PASS same-UUID task creation under observed actor lock contention\n";

        $update='bambodia_tracking_task_update';
        $args=$this->trackingArgs($order,$a,['task_id'=>$r['task_id'],'status'=>'completed']);
        $other=[...$args,'request_id'=>(string)Str::uuid(),'status'=>'in_progress'];
        $u=$this->oneStale($this->race('marketplace_orders',$order->id,$a,$a,$update,$args,$other));
        $this->ledger($a,$u);
        check(DB::table('order_tracking_tasks')->where('id',$r['task_id'])->value('status')===$u['status'],'Task state differs from winner');
        check(DB::table('account_notifications')->where('user_id',$b->id)->where('event','order.tracking.task_updated')->count()===1,'Duplicate update notification');
        echo "PASS competing task updates with same version and distinct UUIDs\n";

        $client=$this->actor(); $freelancer=$this->actor();
        $contract=$this->contract($client,$freelancer);
        $contract->update(['agreed_budget'=>'150.00']);
        $args=$this->contractArgs($contract,$client,['amount'=>'50.00']);
        $r=$this->exactReplay($this->race('freelance_contracts',$contract->id,$client,$client,'bambodia_contract_milestone_create',$args,$args));
        $this->ledger($client,$r);
        check($contract->milestones()->count()===2,'Duplicate milestone');
        check((float)$contract->milestones()->sum('amount')===150.0,'Milestone budget mismatch');
        echo "PASS same-UUID milestone creation under observed parent lock contention\n";

        $buyer=$this->actor(); $seller=$this->actor(); $o=$this->order($buyer,$seller);
        $comment='bambodia_tracking_comment_create';
        check((bool)(\App\Support\EmailOutbox::template('inquiry.replied')['enabled']??false),'Test email template disabled');
        $args=$this->trackingArgs($o,$buyer,['message'=>'Concurrent comment fixture']);
        $r=$this->exactReplay($this->race('service_inquiries',$o->serviceInquiry()->sole()->id,$buyer,$buyer,$comment,$args,$args));
        $this->ledger($buyer,$r);
        check($o->serviceInquiry()->sole()->messages()->count()===1,'Duplicate comment');
        check(DB::table('account_notifications')->where('user_id',$seller->id)->where('event','inquiry.replied')->count()===1,'Duplicate comment notification');
        check(DB::table('email_deliveries')->where('user_id',$seller->id)->where('event','inquiry.replied')->count()===1,'Duplicate outbox entry');
        echo "PASS duplicate comment, notification and email outbox deduplication\n";

        // Different actors contend on the same domain parent. One may deadlock on
        // recipient FK locking; Laravel's transaction retry must preserve stale checks.
        $left=$this->trackingArgs($o,$buyer,['message'=>'Buyer competing comment']);
        $right=$this->trackingArgs($o,$seller,['message'=>'Seller competing comment']);
        $r=$this->oneStale($this->race('marketplace_orders',$o->id,$buyer,$seller,$comment,$left,$right));
        $winner=$r['request_id']===$left['request_id']?$buyer:$seller;
        $this->ledger($winner,$r);
        check($o->serviceInquiry()->sole()->messages()->count()===2,'Competing comment count');
        check(DB::table('account_notifications')->whereIn('user_id',[$buyer->id,$seller->id])->where('event','inquiry.replied')->count()===2,'Competing notification count');
        check(DB::table('email_deliveries')->whereIn('user_id',[$buyer->id,$seller->id])->where('event','inquiry.replied')->count()===2,'Competing outbox count');
        echo "PASS cross-participant parent contention and stale rejection\n";

        $buyer=$this->actor(); $seller=$this->actor(); $o=$this->order($buyer,$seller);
        $args=$this->trackingArgs($o,$buyer,['message'=>'Rollback concurrent comment fixture']);
        $dir=sys_get_temp_dir().'/bambodia-mcp-concurrency-'.bin2hex(random_bytes(12));
        check(mkdir($dir,0700),'Cannot allocate rollback gate');
        $gate=$dir.'/release';
        try {
            $p=$this->worker($buyer,$comment,$args,$gate);
            $this->ready($p);
            awaitCondition(fn()=>$this->line($p,'FAULT_READY')===true,'late audit failure barrier');
            $q=$this->worker($buyer,$comment,$args);
            $this->blocked([$this->ready($q)]);
            check(DB::table('mcp_project_operations')->where('actor_id',$buyer->id)->count()===0,'Uncommitted ledger visible');
            check($o->serviceInquiry()->sole()->messages()->count()===0,'Uncommitted message visible');
            check(DB::table('account_notifications')->where('user_id',$seller->id)->where('event','inquiry.replied')->count()===0,'Uncommitted notification visible');
            check(DB::table('email_deliveries')->where('user_id',$seller->id)->where('event','inquiry.replied')->count()===0,'Uncommitted outbox visible');
            touch($gate);
            check($this->result($p)['kind']==='rolled_back','Injected failure did not roll back');
            $r=$this->result($q);
            check($r['kind']==='ok'&&$r['receipt']['replayed']===false,'Waiting retry did not execute after rollback');
            $this->ledger($buyer,$r['receipt']);
            check($o->serviceInquiry()->sole()->messages()->count()===1,'Rollback left duplicate message');
            check(DB::table('account_notifications')->where('user_id',$seller->id)->where('event','inquiry.replied')->count()===1,'Rollback left duplicate notification');
            check(DB::table('email_deliveries')->where('user_id',$seller->id)->where('event','inquiry.replied')->count()===1,'Rollback left duplicate outbox');
            check(DB::table('audit_events')->where('actor_id',$buyer->id)->whereIn('event',['inquiry.replied','mcp.phase4.tracking_comment_create'])->count()===2,'Rollback left duplicate audit');
        } finally {
            if (is_file($gate)) unlink($gate);
            rmdir($dir);
        }
        echo "PASS late transactional rollback with blocked retry, audit, notification and outbox integrity\n";
        echo "MCP_PROJECT_CONCURRENCY=PASS scenarios=6\n";
    }

    public function cleanup(): void
    {
        foreach ($this->workers as $p) if ($p->isRunning()) $p->stop(1);
    }
}
$acceptance=new ConcurrentProjectAcceptance();
try { $acceptance->run(); } finally { $acceptance->cleanup(); }
