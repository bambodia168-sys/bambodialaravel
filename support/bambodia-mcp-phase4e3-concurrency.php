<?php
/** Independent-process acceptance, disposable MySQL CI only. */
declare(strict_types=1);
use App\Models\{AuditEvent,CommercePaymentMethod,MarketplaceInvoice,MarketplaceListing,MarketplaceOrder,User};
use App\Support\Mcp\{InvoiceReadTools,InvoiceWriteTools};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\{DB,Http};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

function demand(bool $ok,string $message): void {if(!$ok) throw new RuntimeException($message);}
demand(getenv('GITHUB_ACTIONS')==='true' && getenv('INVOICE_CONCURRENCY_ISOLATED')==='1'
    && getenv('APP_ENV')==='testing' && getenv('DB_CONNECTION')==='mysql' && getenv('DB_HOST')==='127.0.0.1'
    && getenv('DB_DATABASE')==='bambodia_invoice_acceptance' && getenv('DB_URL')==='','Disposable CI only.');
$root=dirname(__DIR__);
require $root.'/vendor/autoload.php';
$app=require $root.'/bootstrap/app.php';$app->make(Kernel::class)->bootstrap();
demand(app()->environment('testing') && config('database.default')==='mysql'
    && config('database.connections.mysql.host')==='127.0.0.1'
    && config('database.connections.mysql.database')==='bambodia_invoice_acceptance'
    && !config('database.connections.mysql.url')
    && DB::selectOne('SELECT DATABASE() AS db')->db==='bambodia_invoice_acceptance'
    && config('mail.default')==='array','Unsafe database or mail identity.');
config(['commerce.enabled'=>true,'commerce.payments_enabled'=>false,'commerce.live_gateways'=>false,
    'invoices.enabled'=>true,'invoices.draft_writes_enabled'=>true,'mcp.production_writes_enabled'=>true]);
Http::preventStrayRequests();
DB::statement('SET SESSION innodb_lock_wait_timeout = 10');

function writeInvoice(int $actor,string $action,array $args): array {
    try {
        return ['ok'=>true,'receipt'=>app(InvoiceWriteTools::class)->call('bambodia_invoice_draft_'.$action,$args,User::query()->findOrFail($actor),true,true)];
    } catch(ValidationException $e) {return ['ok'=>false,'fields'=>array_keys($e->errors())];}
}
if(($argv[1]??'')==='worker') {
    $payload=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $ready=$payload['ready'];
    demand(is_string($ready) && str_starts_with($ready,sys_get_temp_dir().'/invoice-concurrency-'),'Invalid barrier.');
    file_put_contents($ready,'ready',LOCK_EX);
    $r=writeInvoice($payload['actor'],$payload['action'],$payload['args']);
    echo json_encode($r,JSON_THROW_ON_ERROR);
    exit;
}
demand(($argv[1]??'')==='', 'Invalid invocation.');
demand(!User::query()->exists() && !MarketplaceOrder::query()->exists() && !MarketplaceInvoice::query()->exists(),'Expected empty fixture database after transactional tests.');
function snapshot(): array {
    $out=[];
    foreach(['marketplace_orders','commerce_payments','commerce_order_operations','account_notifications','email_deliveries','product_entitlements','service_inquiries'] as $t)
        $out[$t]=hash('sha256',DB::table($t)->orderBy('id')->get()->toJson());
    return $out;
}
function actor(): User {
    return User::query()->create(['name'=>'Synthetic concurrency actor','email'=>Str::uuid().'@example.test',
        'password'=>'FixtureOnly123!','role'=>'super_admin','account_status'=>'active']);
}
function order(User $a,User $b): MarketplaceOrder {
    $l=MarketplaceListing::query()->create(['user_id'=>$a->id,'type'=>'service','title'=>'Synthetic concurrency line',
        'description'=>'Disposable test only','status'=>'published','price'=>'25.00','currency'=>'USD']);
    return MarketplaceOrder::query()->create(['number'=>(string)Str::uuid(),'buyer_id'=>$b->id,'seller_id'=>$a->id,
        'marketplace_listing_id'=>$l->id,'request_key'=>(string)Str::uuid(),'listing_title'=>$l->title,
        'buyer_name'=>$b->name,'seller_name'=>$a->name,'total'=>'25.00','currency'=>'USD','status'=>'awaiting_payment']);
}
function createArgs(User $a,MarketplaceOrder $o): array {
    $p=app(InvoiceReadTools::class)->call('bambodia_invoice_source_preview',['order_reference'=>$o->reference()],$a,true);
    return ['order_reference'=>$o->reference(),'expected_version'=>$p['source_version'],'request_id'=>(string)Str::uuid(),'confirm'=>true];
}
function draftArgs(array $r): array {
    return ['invoice_id'=>$r['invoice_id'],'expected_version'=>$r['version'],'request_id'=>(string)Str::uuid(),'confirm'=>true];
}
function launch(User $a,array $jobs,callable $hold): array {
    $workers=[];$paths=[];$results=[];
    DB::beginTransaction();
    try {
        // The parent takes a real row lock before starting independent sessions.
        // All workers must reach the barrier and remain blocked before release.
        $hold();
        foreach($jobs as [$action,$args]) {
            $ready=tempnam(sys_get_temp_dir(),'invoice-concurrency-');
            demand($ready!==false,'Cannot create barrier');unlink($ready);$paths[]=$ready;
            $p=new Process([PHP_BINARY,__FILE__,'worker'],dirname(__DIR__));
            $p->setTimeout(30);
            $p->setInput(json_encode(['actor'=>$a->id,'action'=>$action,'args'=>$args,'ready'=>$ready],JSON_THROW_ON_ERROR));
            $p->start();$workers[]=$p;
        }
        $deadline=microtime(true)+8;
        do {
            $all=true;
            foreach($paths as $path) if(!is_file($path)) {$all=false;break;}
            if($all) break;
            usleep(20000);
        } while(microtime(true)<$deadline);
        demand($all,'Workers did not reach independent session barrier.');
        usleep(350000);
        foreach($workers as $p) demand($p->isRunning(),'Worker unexpectedly completed while parent lock held.');
        DB::commit();
        foreach($workers as $p) {
            $p->wait();demand($p->isSuccessful(),'Worker process failed.');
            $results[]=json_decode($p->getOutput(),true,512,JSON_THROW_ON_ERROR);
        }
        return $results;
    } finally {
        if(DB::transactionLevel()>0) DB::rollBack();
        foreach($workers as $p) if($p->isRunning()) $p->stop(1);
        foreach($paths as $path) if(is_file($path)) unlink($path);
    }
}
function success(array $r): array {demand(($r['ok']??false)===true,'Expected successful operation');return $r['receipt'];}
function failure(array $r,string $field): void {demand(($r['ok']??true)===false && $r['fields']===[$field],'Expected controlled rejection: '.$field);}
function sameActorLock(User $a): Closure {return fn()=>User::query()->whereKey($a->id)->lockForUpdate()->firstOrFail();}

$a=actor();$b=actor();
$o=order($a,$b);$args=createArgs($a,$o);$baseline=snapshot();
$results=launch($a,[['create',$args],['create',$args]],sameActorLock($a));
$x=success($results[0]);$y=success($results[1]);
demand($x['replayed']!==$y['replayed'] && [...$x,'replayed'=>false]===[...$y,'replayed'=>false],'Exact replay race mismatch.');
demand(MarketplaceInvoice::query()->count()===1 && DB::table('mcp_invoice_operations')->count()===1,'Duplicate exact replay.');
demand(snapshot()===$baseline,'Protected records changed.');
echo "CONCURRENT_SAME_UUID_CREATE=PASS\n";

$o=order($a,$b);$args=createArgs($a,$o);$baseline=snapshot();
$results=launch($a,[['create',$args],['create',[...$args,'request_id'=>(string)Str::uuid()]]],sameActorLock($a));
$winners=array_values(array_filter($results,fn($r)=>$r['ok']));
$losers=array_values(array_filter($results,fn($r)=>!$r['ok']));
demand(count($winners)===1 && count($losers)===1,'Distinct create race mismatch.');
failure($losers[0],'conflict');$r=success($winners[0]);
demand(MarketplaceInvoice::query()->where('marketplace_order_id',$o->id)->count()===1 && DB::table('mcp_invoice_operations')->count()===2,'Duplicate source record.');
demand(snapshot()===$baseline,'Protected records changed.');
echo "CONCURRENT_DISTINCT_UUID_CREATE=PASS\n";

$u=[...draftArgs($r),'due_on'=>now(config('invoices.timezone'))->addDay()->toDateString()];$d=draftArgs($r);
$results=launch($a,[['update',$u],['discard',$d]],sameActorLock($a));
$winners=array_values(array_filter($results,fn($r)=>$r['ok']));$losers=array_values(array_filter($results,fn($r)=>!$r['ok']));
demand(count($winners)===1 && count($losers)===1,'Update/discard race mismatch.');
failure($losers[0],'conflict');
$i=MarketplaceInvoice::query()->where('public_id',$r['invoice_id'])->sole();
demand($i->revision===2 && $i->status===success($winners[0])['status'] && DB::table('mcp_invoice_operations')->count()===3,'Conflicting state mutation.');
demand(snapshot()===$baseline,'Protected records changed.');
echo "CONCURRENT_UPDATE_DISCARD=PASS\n";

$o=order($a,$b);$args=createArgs($a,$o);
$method=CommercePaymentMethod::query()->firstOrCreate(['name'=>'Synthetic race transfer'],['available'=>true]);
// Simulate an authoritative source transition in the isolated database. No gateway or native collection invocation.
$results=launch($a,[['create',$args]],function()use($o,$method): void {
    $locked=MarketplaceOrder::query()->whereKey($o->id)->lockForUpdate()->firstOrFail();
    $locked->payments()->create(['number'=>(string)Str::uuid(),'commerce_payment_method_id'=>$method->id,'method_name'=>$method->name,
        'provider'=>'bank_transfer','status'=>'paid','live_mode'=>true,'settlement_reference'=>'QA-ONLY-'.Str::uuid()]);
    $locked->update(['status'=>'completed','receipt_number'=>(string)Str::uuid(),'completed_at'=>now()]);
});
failure($results[0],'resource');demand(!MarketplaceInvoice::query()->where('marketplace_order_id',$o->id)->exists(),'Paid source created invoice.');
demand($o->fresh()->status==='completed' && $o->payments()->count()===1 && DB::table('mcp_invoice_operations')->count()===3,'Source transition overwritten.');
echo "CONCURRENT_SOURCE_PAYMENT_TRANSITION=PASS\n";

$o=order($a,$b);$args=createArgs($a,$o);$baseline=snapshot();$attempts=0;
// Exercise Laravel's bounded retry at a late transaction point with a synthetic
// SQLSTATE 40001 exception. This is not a claim of engine-selected deadlock coverage.
AuditEvent::creating(function(AuditEvent $event)use(&$attempts): void {
    if($event->event==='mcp.invoice.draft.create' && ++$attempts===1)
        throw new PDOException('Deadlock found when trying to get lock; try restarting transaction',40001);
});
try {$r=success(writeInvoice($a->id,'create',$args));}
finally {AuditEvent::flushEventListeners();}
demand($attempts===2 && !$r['replayed'] && DB::table('mcp_invoice_operations')->count()===4,'Retry did not commit exactly once.');
demand(snapshot()===$baseline,'Protected records changed after retry.');
echo "SYNTHETIC_LATE_DEADLOCK_RETRY=PASS\n";

$o=order($a,$b);$args=createArgs($a,$o);$baseline=snapshot();$attempts=0;
AuditEvent::creating(function(AuditEvent $event)use(&$attempts): void {
    if($event->event==='mcp.invoice.draft.create') {++$attempts;throw new PDOException('Deadlock found when trying to get lock; try restarting transaction',40001);}
});
try {
    try {writeInvoice($a->id,'create',$args);throw new RuntimeException('Expected exhausted retry.');}
    catch(PDOException $e) {demand((int)$e->getCode()===40001,'Unexpected SQLSTATE.');}
} finally {AuditEvent::flushEventListeners();}
demand($attempts===3 && !MarketplaceInvoice::query()->where('marketplace_order_id',$o->id)->exists(),'Retry bound/rollback failed.');
demand(DB::table('mcp_invoice_operations')->count()===4 && snapshot()===$baseline,'Retry exhaustion changed records.');
demand(AuditEvent::query()->where('event','like','invoice.draft.%')->count()===4 && AuditEvent::query()->where('event','like','mcp.invoice.draft.%')->count()===4,'Duplicate or orphan audit events.');
echo "BOUNDED_RETRY_EXHAUSTION_ROLLBACK=PASS\nINDEPENDENT_MYSQL_CONCURRENCY=PASS\n";
