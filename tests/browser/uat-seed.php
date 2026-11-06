<?php
/** Disposable HTTP acceptance fixtures. NEVER run on a deployed site. */
declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if(!app()->environment('testing')
    || getenv('BAMBODIA_UAT_ISOLATED')!=='1'
    || config('app.url')!=='http://127.0.0.1:8096'
    || config('database.default')!=='mysql'
    || config('database.connections.mysql.database')!=='bambodia_uat'
    || !in_array(config('database.connections.mysql.host'),['127.0.0.1','localhost'],true)
    || \App\Models\User::query()->exists()){
    fwrite(STDERR,"BLOCKED: requires empty isolated localhost MySQL QA database.\n");
    exit(2);
}

$make=static fn(string $name,string $role):\App\Models\User=>\App\Models\User::query()->create([
    'name'=>'UAT '.$name,
    'email'=>'uat.'.strtolower($name).'@example.test',
    'role'=>$role,'password'=>'UatIsolatedBrowserOnly123!',
]);
$client=$make('Client','customer');
$creator=$make('Creator','freelancer');
$staff=$make('Staff','staff');
$moderator=$make('Moderator','admin');
$super=$make('Super','super_admin');
$outsider=$make('Outsider','customer');

$license=\App\Models\LicenseDefinition::query()->create([
    'code'=>'UAT_ORIGINAL','name'=>'UAT Original Work License','version'=>'1.0.0',
    'permissions'=>str_repeat('QA-only licensed original work. ',3),
    'restrictions'=>str_repeat('No actual commercial redistribution. ',3),
    'status'=>'active','published_at'=>now(),'created_by'=>$super->id,
]);
$png=base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl+R1gAAAAASUVORK5CYII=',true);
if($png===false)throw new RuntimeException('Missing QA image');
$path='marketplace/previews/'.$creator->id.'/uat-cover.png';
\Illuminate\Support\Facades\Storage::disk('local')->put($path,$png);
$cover=\App\Models\MediaAsset::query()->create([
    'user_id'=>$creator->id,'original_name'=>'uat-cover.png',
    'storage_path'=>$path,'mime_type'=>'image/png','bytes'=>strlen($png),
    'width'=>1,'height'=>1,'alt_text'=>'UAT approved original cover',
    'rights_basis'=>'owned','rights_holder'=>'UAT Creator',
    'review_status'=>'approved','reviewed_by'=>$moderator->id,'reviewed_at'=>now(),
]);
$category=\App\Models\MarketplaceCategory::query()->create([
    'type'=>'product','name'=>'UAT Digital Tools',
    'is_active'=>true,'sort_order'=>1,'created_by'=>$moderator->id,
]);
$listing=static fn(string $title,string $status):\App\Models\MarketplaceListing=>
    \App\Models\MarketplaceListing::query()->create([
        'user_id'=>$creator->id,'title'=>$title,'type'=>'service',
        'description'=>str_repeat('QA-only professional service delivery. ',3),
        'status'=>$status,'price'=>'100.00','currency'=>'USD',
    ]);
$approve=$listing('UAT Service For Approval','pending');
$reject=$listing('UAT Service For Rejection','pending');
$published=$listing('UAT Published Service For Messaging','published');

$job=\App\Models\MarketplaceJob::query()->create([
    'client_id'=>$client->id,'title'=>'UAT Independent Contract Workflow',
    'description'=>str_repeat('Isolated contract and deliverable acceptance. ',3),
    'category'=>'development','budget'=>'100.00','status'=>'published',
]);
$proposal=\App\Models\JobProposal::query()->create([
    'marketplace_job_id'=>$job->id,'freelancer_id'=>$creator->id,
    'cover_message'=>str_repeat('QA-only proposed deliverables. ',3),
    'proposed_budget'=>'100.00','status'=>'shortlisted',
]);
$contract=\App\Models\FreelanceContract::query()->create([
    'job_proposal_id'=>$proposal->id,'client_id'=>$client->id,
    'freelancer_id'=>$creator->id,'title'=>'UAT Verified Contract Journey',
    'scope'=>str_repeat('Complete an isolated original QA deliverable. ',3),
    'terms'=>str_repeat('No payment, escrow or real-world agreement involved. ',3),
    'currency'=>'USD','agreed_budget'=>'100.00','status'=>'draft',
]);
\App\Models\ContractMilestone::query()->create([
    'freelance_contract_id'=>$contract->id,'sequence'=>1,
    'title'=>'UAT Milestone Delivery',
    'description'=>str_repeat('Document acceptance criteria for this test. ',3),
    'amount'=>'100.00','status'=>'planned',
]);
$fixtures=[
    'approve_id'=>$approve->id,'reject_id'=>$reject->id,'public_service_id'=>$published->id,
    'contract_id'=>$contract->public_id,'license_id'=>$license->id,
    'cover_id'=>$cover->id,'category_id'=>$category->id,
];
$file=dirname(__DIR__,2).'/storage/framework/uat-fixtures.json';
file_put_contents($file,json_encode($fixtures,JSON_THROW_ON_ERROR));
chmod($file,0600);
echo "PASS: six disposable role accounts, original product fixtures, pending decisions, private contract.\n";
