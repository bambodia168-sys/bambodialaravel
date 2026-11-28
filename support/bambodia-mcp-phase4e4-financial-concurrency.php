<?php
/** Independent-process acceptance, disposable MySQL CI only. */
declare(strict_types=1);
use App\Models\{CommercePayment,AuditEvent,CommercePaymentMethod,MarketplaceInvoice,MarketplaceListing,MarketplaceOrder,User};
use App\Support\Mcp\{InvoiceReadTools,InvoiceWriteTools};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\{DB,Http,Schema,Storage,Mail,Queue};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use App\Http\Controllers\CommercePaymentController;
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

Mail::fake();Queue::fake();
if(($argv[1]??'')==='worker') {
    $payload=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $ready=$payload['ready'];
    demand(is_string($ready) && str_starts_with($ready,sys_get_temp_dir().'/invoice-concurrency-'),'Invalid barrier.');
    $attempts=0;$deadlocks=0;
    if($payload['action']==='deadlock') {
        // Real engine cycle at the late audit point. The other session owns the
        // fixture row; the invoice transaction owns the participant/order locks.
        AuditEvent::creating(function(AuditEvent $event)use(&$attempts,&$deadlocks,$ready): void {
            if($event->event!=='mcp.invoice.draft.create') return;
            if(++$attempts===1) {
                file_put_contents($ready,'late-audit',LOCK_EX);
                try {DB::table('invoice_deadlock_fixture')->where('id',1)->lockForUpdate()->first();}
                catch(QueryException $e) {
                    if(($e->errorInfo[0]??null)==='40001' && (int)($e->errorInfo[1]??0)===1213) ++$deadlocks;
                    throw $e;
                }
            }
        });
    } else file_put_contents($ready,'ready',LOCK_EX);
    $r=writeInvoice($payload['actor'],$payload['action']==='deadlock'?'create':$payload['action'],$payload['args']);
    echo json_encode([...$r,'attempts'=>$attempts,'engine_deadlocks'=>$deadlocks],JSON_THROW_ON_ERROR);
    exit;
}
demand(($argv[1]??'')==='', 'Invalid invocation.');
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


function awaitBarrier(string $file,Process $p): void {
    $deadline=microtime(true)+8;
    while(!is_file($file) && microtime(true)<$deadline && $p->isRunning()) usleep(20000);
    demand(is_file($file)&&$p->isRunning(),'Worker failed to reach barrier.');
}
function invoiceCount(): int {return MarketplaceInvoice::query()->count();}
function ledgerCount(): int {return DB::table('mcp_invoice_operations')->count();}
$a=actor();$b=actor();$reviewer=actor();
$o=order($a,$b);$args=createArgs($a,$o);$baseline=snapshot();
$invoiceBefore=invoiceCount();$ledgerBefore=ledgerCount();
$nativeBefore=AuditEvent::query()->where('event','invoice.draft.create')->count();
$mcpBefore=AuditEvent::query()->where('event','mcp.invoice.draft.create')->count();
demand(!Schema::hasTable('invoice_deadlock_fixture'),'Unexpected scratch table.');
Schema::create('invoice_deadlock_fixture',function(Blueprint $t): void {$t->id();$t->integer('weight');});
DB::table('invoice_deadlock_fixture')->insert(array_map(fn($n)=>['id'=>$n,'weight'=>0],range(1,500)));
$ready=tempnam(sys_get_temp_dir(),'invoice-concurrency-');demand($ready!==false,'Barrier unavailable');unlink($ready);
$p=new Process([PHP_BINARY,__FILE__,'worker'],dirname(__DIR__));$p->setTimeout(30);
try {
    demand((int)DB::selectOne('SELECT @@innodb_deadlock_detect AS enabled')->enabled===1,'Engine deadlock detection required.');
    DB::beginTransaction();
    // Weight the competing transaction so InnoDB selects the much smaller
    // invoice transaction as victim. Assert actual 1213 in that worker.
    DB::table('invoice_deadlock_fixture')->update(['weight'=>1]);
    $p->setInput(json_encode(['actor'=>$a->id,'action'=>'deadlock','args'=>$args,'ready'=>$ready],JSON_THROW_ON_ERROR));
    $p->start();awaitBarrier($ready,$p);
    User::query()->whereKey($a->id)->lockForUpdate()->firstOrFail();
    DB::commit();
    $p->wait();demand($p->isSuccessful(),'Deadlock worker failed.');
    $result=json_decode($p->getOutput(),true,512,JSON_THROW_ON_ERROR);$receipt=success($result);
    demand($result['engine_deadlocks']===1 && $result['attempts']===2 && !$receipt['replayed'],'Real engine victim/retry evidence missing.');
    demand(invoiceCount()===$invoiceBefore+1 && ledgerCount()===$ledgerBefore+1,'Duplicate mutation after engine deadlock.');
    demand(AuditEvent::query()->where('event','invoice.draft.create')->count()===$nativeBefore+1 &&
        AuditEvent::query()->where('event','mcp.invoice.draft.create')->count()===$mcpBefore+1,'Orphan or duplicate audit.');
    demand(DB::table('mcp_invoice_operations')->where('request_id',$args['request_id'])->where('audit_event_id',$receipt['audit_id'])->exists(),'Audit linkage mismatch.');
    demand(snapshot()===$baseline,'Protected records changed.');
    echo "MYSQL_ENGINE_DEADLOCK_SQLSTATE_40001_DRIVER_1213=PASS\nMYSQL_ENGINE_DEADLOCK_RETRY_EXACTLY_ONCE=PASS\n";
} finally {
    if(DB::transactionLevel()>0) DB::rollBack();
    if($p->isRunning()) $p->stop(1);
    if(is_file($ready)) unlink($ready);
    Schema::dropIfExists('invoice_deadlock_fixture');
}

Storage::fake('local');
$method=CommercePaymentMethod::query()->firstOrCreate(['name'=>'Synthetic native manual bank'],['available'=>true]);
function manualAttempt(MarketplaceOrder $o,$method): CommercePayment {
    $path='synthetic-invoice-review/'.Str::uuid().'.pdf';
    Storage::disk('local')->put($path,'synthetic proof, no real payment');
    return $o->payments()->create(['number'=>(string)Str::uuid(),'commerce_payment_method_id'=>$method->id,
        'method_name'=>$method->name,'provider'=>'bank_transfer','status'=>'manual_review','live_mode'=>true,'proof_path'=>$path]);
}
function nativeApprove(User $reviewer,MarketplaceOrder $o,CommercePayment $p): void {
    $request=Request::create('/synthetic-native-review','PATCH',['decision'=>'approve','note'=>'Isolated synthetic manual review',
        'verified_reference'=>'QA-'.Str::uuid(),'verified_amount'=>$o->total]);
    $request->setUserResolver(fn()=>$reviewer);
    $session=app('session')->driver();$session->start();$request->setLaravelSession($session);
    app()->instance('request',$request);app('redirect')->setSession($session);
    $response=app(CommercePaymentController::class)->review($request,$o->number,$p->number);
    demand($response->isRedirect(),'Native manual review did not complete.');
}
foreach(['create','update'] as $action) {
    $o=order($a,$b);$attempt=manualAttempt($o,$method);$args=createArgs($a,$o);
    if($action==='update') {
        $draft=success(writeInvoice($a->id,'create',$args));
        $args=[...draftArgs($draft),'due_on'=>now(config('invoices.timezone'))->addDay()->toDateString()];
    }
    $before=invoiceCount();$ledger=ledgerCount();$afterNative=null;
    // Execute the real native controller transaction nested in the parent
    // transaction. The invoice worker must wait for its actual order lock.
    $r=launch($a,[[$action,$args]],function()use($reviewer,$o,$attempt,&$afterNative): void {
        nativeApprove($reviewer,$o,$attempt);
        $afterNative=snapshot();
    });
    failure($r[0],$action==='create'?'resource':'conflict');
    demand($o->fresh()->status==='completed' && $o->fresh()->receipt_number!==null &&
        $attempt->fresh()->status==='paid' && str_starts_with($attempt->fresh()->settlement_reference,'manual:QA-'),'Native settlement failed.');
    demand(invoiceCount()===$before && ledgerCount()===$ledger && snapshot()===$afterNative,'Invoice request changed native payment effects.');
    if($action==='update') {
        $i=MarketplaceInvoice::query()->where('public_id',$args['invoice_id'])->sole();
        demand($i->revision===1 && $i->due_on===null,'Paid-source update mutated draft.');
        $d=app(InvoiceReadTools::class)->call('bambodia_invoice_get',['invoice_id'=>$i->public_id],$a,true);
        demand($d['payment_state']==='paid' && !isset($d['version']) && isset($d['discard_version']),'Paid draft state mismatch.');
    }
    echo 'NATIVE_MANUAL_APPROVAL_VS_'.strtoupper($action)."=PASS\n";
}
Mail::assertNothingSent();Mail::assertNothingQueued();Queue::assertNothingPushed();
demand(DB::table('email_deliveries')->where('status','sent')->count()===0,'External delivery occurred.');
echo "NATIVE_PAYMENT_TRANSITION_CONCURRENCY=PASS\nEXTERNAL_DELIVERY=NONE\n";
