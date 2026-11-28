<?php
/** Disposable synthetic acceptance fixtures; never run on a deployed site. */
declare(strict_types=1);
use App\Models\{MarketplaceInvoice, MarketplaceInvoiceItem, MarketplaceListing, MarketplaceOrder, User};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function demand(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
demand(getenv('GITHUB_ACTIONS')==='true' && getenv('INVOICE_BROWSER_ISOLATED')==='1'
    && getenv('APP_ENV')==='testing' && getenv('DB_HOST')==='127.0.0.1'
    && getenv('DB_DATABASE')==='bambodia_invoice_browser' && getenv('DB_URL')===''
    && getenv('APP_URL')==='http://127.0.0.1:8098','Isolated CI only.');
$base=dirname(__DIR__,2);
require $base.'/vendor/autoload.php';
$app=require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
demand(app()->environment('testing') && config('database.default')==='mysql'
    && config('database.connections.mysql.host')==='127.0.0.1'
    && config('database.connections.mysql.database')==='bambodia_invoice_browser'
    && DB::selectOne('SELECT DATABASE() AS db')->db==='bambodia_invoice_browser'
    && config('app.url')==='http://127.0.0.1:8098' && config('invoices.enabled') && config('invoices.draft_writes_enabled')
    && config('commerce.enabled') && !config('commerce.payments_enabled') && !config('commerce.live_gateways')
    && config('mail.default')==='array','Unsafe fixture configuration.');
function snapshot(): array {
    $out=[];
    foreach (['marketplace_orders','commerce_payments','commerce_order_operations','account_notifications',
        'email_deliveries','product_entitlements','service_inquiries'] as $table)
        $out[$table]=hash('sha256',DB::table($table)->orderBy('id')->get()->toJson());
    return $out;
}
$f=$base.'/storage/framework/native-invoice-browser.json';
$b=$base.'/storage/framework/native-invoice-browser-baseline.json';
switch ($argv[1]??'') {
case 'seed':
    demand(!User::query()->exists() && !MarketplaceOrder::query()->exists() && !MarketplaceInvoice::query()->exists(),'Database not empty.');
    $users=[];
    foreach (['seller'=>'freelancer','buyer'=>'customer','recipient'=>'customer','outsider'=>'freelancer','admin'=>'super_admin'] as $role=>$type) {
        $users[$role]=User::query()->create(['name'=>'Invoice QA '.ucfirst($role),'email'=>'invoice-browser.'.$role.'@example.test',
            'password'=>'InvoiceBrowserOnly123!','role'=>$type,'account_status'=>'active']);
        $users[$role]->markEmailAsVerified();
    }
    $seller=$users['seller'];
    $listing=MarketplaceListing::query()->create(['user_id'=>$seller->id,'type'=>'service','title'=>'Synthetic original invoice item',
        'description'=>'Disposable browser fixture only.','status'=>'published','currency'=>'USD','price'=>'25.00']);
    $order=fn(User $buyer)=>MarketplaceOrder::query()->create(['number'=>(string)Str::uuid(),'buyer_id'=>$buyer->id,'seller_id'=>$seller->id,
        'marketplace_listing_id'=>$listing->id,'request_key'=>(string)Str::uuid(),'listing_title'=>$listing->title,
        'buyer_name'=>$buyer->name,'seller_name'=>$seller->name,'total'=>'25.00','currency'=>'USD','status'=>'awaiting_payment']);
    $source=$order($users['buyer']);
    $fixture=['reference'=>$source->reference(),'date'=>now(config('invoices.timezone'))->addDays(10)->toDateString(),
        'updatedDate'=>now(config('invoices.timezone'))->addDays(20)->toDateString()];
    // Direct fixture insertion of future states: no issuance action exists or is exercised.
    foreach (['issued','void'] as $status) {
        $o=$order($users['recipient']);
        $digest=hash_hmac('sha256','invoice-snapshot-v1:'.json_encode([(int)$o->id,(string)$o->public_reference,
            (int)$o->seller_id,(int)$o->buyer_id,(string)$o->listing_title,(string)$o->currency,'25.00'],
            JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),(string)config('app.key'));
        $i=new MarketplaceInvoice();
        $i->forceFill(['public_id'=>(string)Str::uuid(),'marketplace_order_id'=>$o->id,'issuer_id'=>$seller->id,'recipient_id'=>$users['recipient']->id,
            'currency'=>'USD','subtotal'=>'25.00','total'=>'25.00','status'=>$status,'revision'=>1,'snapshot_digest'=>$digest,
            'invoice_number'=>'QA-ONLY-'.strtoupper($status),'issued_at'=>now(),'voided_at'=>$status==='void'?now():null])->save();
        (new MarketplaceInvoiceItem())->forceFill(['marketplace_invoice_id'=>$i->id,'position'=>1,'quantity'=>1,
            'description'=>$o->listing_title,'unit_amount'=>'25.00','line_total'=>'25.00'])->save();
        $fixture[$status]=$i->public_id;
    }
    $listing->update(['title'=>'Changed synthetic listing','price'=>'99.00']);
    file_put_contents($f,json_encode($fixture,JSON_THROW_ON_ERROR));
    echo "SYNTHETIC_FIXTURES=PASS\n";
    break;
case 'baseline':
    demand(MarketplaceInvoice::query()->count()===2 && DB::table('mcp_invoice_operations')->count()===0,'Unexpected baseline.');
    file_put_contents($b,json_encode(snapshot(),JSON_THROW_ON_ERROR));
    echo "PROTECTED_TABLE_BASELINE=PASS\n";
    break;
case 'verify':
    $fixture=json_decode(file_get_contents($f),true,512,JSON_THROW_ON_ERROR);
    demand(snapshot()===json_decode(file_get_contents($b),true,512,JSON_THROW_ON_ERROR),'Protected tables changed.');
    $i=MarketplaceInvoice::query()->whereHas('order',fn($q)=>$q->where('public_reference',$fixture['reference']))->sole();
    demand($i->status==='discarded' && $i->revision===4 && $i->due_on===null && $i->invoice_number===null
        && $i->issued_at===null && $i->discarded_at!==null && $i->total==='25.00','Lifecycle mismatch.');
    $line=$i->items()->sole();
    demand($line->description==='Synthetic original invoice item' && $line->line_total==='25.00','Original line changed.');
    demand(MarketplaceInvoice::query()->count()===3 && MarketplaceInvoiceItem::query()->count()===3,'Unexpected records.');
    $ops=DB::table('mcp_invoice_operations')->orderBy('id')->get();
    demand($ops->pluck('tool')->all()===['invoice.draft.create','invoice.draft.update','invoice.draft.update','invoice.draft.discard'],'Operation history mismatch.');
    foreach($ops as $op) demand($op->resource_reference===$i->public_id && (int)$op->actor_id===(int)$i->issuer_id
        && DB::table('audit_events')->where('id',$op->audit_event_id)->where('event',$op->tool)->where('actor_id',$i->issuer_id)->exists(),'Audit linkage mismatch.');
    demand(DB::table('audit_events')->where('event','like','invoice.draft.%')->count()===4,'Unexpected audit events.');
    foreach(['issued','void'] as $status) demand(MarketplaceInvoice::query()->where('public_id',$fixture[$status])->sole()->status===$status,'Fixture changed.');
    echo "DRAFT_LIFECYCLE_AND_AUDIT=PASS\nPROTECTED_TABLES_UNCHANGED=PASS\n";
    break;
default: throw new RuntimeException('Unknown fixture operation.');
}
