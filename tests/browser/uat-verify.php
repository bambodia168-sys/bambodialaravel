<?php
/** Read-only independent database check after real-HTTP QA browser actions. */
declare(strict_types=1);
require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('testing') || getenv('BAMBODIA_UAT_ISOLATED')!=='1'
    || config('app.url')!=='http://127.0.0.1:8096'
    || config('database.default')!=='mysql'
    || config('database.connections.mysql.database')!=='bambodia_uat'
    || !in_array(config('database.connections.mysql.host'),['127.0.0.1','localhost'],true)){
    fwrite(STDERR,"BLOCKED: read-only verifier requires isolated QA MySQL.\n"); exit(2);
}
$creator=\App\Models\User::query()->where('email','uat.creator@example.test')->firstOrFail();
$client=\App\Models\User::query()->where('email','uat.client@example.test')->firstOrFail();
$staff=\App\Models\User::query()->where('email','uat.staff@example.test')->firstOrFail();
$super=\App\Models\User::query()->where('email','uat.super@example.test')->firstOrFail();
$outsider=\App\Models\User::query()->where('email','uat.outsider@example.test')->firstOrFail();
$prod=\App\Models\MarketplaceListing::query()->where('title','UAT Original Product for Review')->first();
$support=\App\Models\SupportTicket::query()->where('subject','UAT Private Support Response')->first();
$inquiry=\App\Models\ServiceInquiry::query()->where('subject','UAT Private Service Conversation')->first();
$contract=\App\Models\FreelanceContract::query()->where('title','UAT Verified Contract Journey')->first();
$token=\App\Models\ApiToken::query()->where('name','UAT Revocable Credential')->first();
$event=static fn(string $name,int $actor):bool=>\App\Models\AuditEvent::query()
    ->where('event',$name)->where('actor_id',$actor)->exists();
$notification=static fn(string $name,int $recipient):bool=>\App\Models\AccountNotification::query()
    ->where('event',$name)->where('user_id',$recipient)->exists();
$tests=[
 'moderator-published'=>\App\Models\MarketplaceListing::query()
    ->where('title','UAT Service For Approval')->where('status','published')->exists(),
 'moderator-rejected'=>\App\Models\MarketplaceListing::query()
    ->where('title','UAT Service For Rejection')->where('status','rejected')->exists(),
 'product-published'=>$prod && $prod->status==='published',
 'release-held-pending'=>$prod && \App\Models\ProductFileVersion::query()
    ->where('marketplace_listing_id',$prod->id)->where('status','pending')->exists(),
 'creator-review-notified'=>$notification('listing.reviewed',$creator->id),
 'support-response-recorded'=>$support && $support->status==='in_progress' && $support->messages()->count()===2,
 'support-audited'=>$event('support.staff.replied',$staff->id),
 'support-notified'=>$notification('support.replied',$client->id),
 'inquiry-message-recorded'=>$inquiry && $inquiry->messages()->count()===2,
 'inquiry-audited'=>$event('inquiry.replied',$creator->id),
 'inquiry-notified'=>$notification('inquiry.replied',$client->id),
 'contract-completed'=>$contract && $contract->status==='completed',
 'contract-consent-recorded'=>$contract && $contract->consents()->count()===2,
 'contract-audited'=>$event('contract.completed',$client->id),
 'contract-notified'=>$notification('contract.completed',$creator->id),
 'token-revoked'=>$token && $token->user_id===$super->id && $token->revoked_at!==null,
 'token-audited'=>$event('developer.token.revoked',$super->id),
 'outsider-unnotified'=>\App\Models\AccountNotification::query()->where('user_id',$outsider->id)->count()===0,
];
$blocked=count(array_filter($tests,static fn($passed)=>!$passed));
$report=[
 'kind'=>'independent-database-verification',
 'environment'=>'isolated MySQL QA only',
 'status'=>$blocked?'blocked':'passed',
 'timestamp_utc'=>gmdate('c'),
 'checks'=>array_map(static fn($passed)=>$passed?'pass':'block',$tests),
 'blocker_count'=>$blocked,
 'limitations'=>[
   'No customer data, production secrets or financial transactions were accessed.',
   'Real ClamAV approvals, legal license reviews and real-host device acceptance are not certified by this report.',
 ],
];
$dest=dirname(__DIR__,2).'/uat-acceptance-results';
if(!is_dir($dest))mkdir($dest,0700,true);
file_put_contents($dest.'/database-report.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
foreach($report['checks'] as $name=>$status) echo strtoupper($status).' '.$name."\n";
exit($blocked?2:0);
