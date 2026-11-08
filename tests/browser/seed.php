<?php

declare(strict_types=1);

require dirname(__DIR__,2).'/vendor/autoload.php';
$app=require dirname(__DIR__,2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if(!app()->environment('testing')){
    fwrite(STDERR,"Refusing to seed browser fixtures outside APP_ENV=testing.\n");
    exit(2);
}
$client=\App\Models\User::query()->create([
    'name'=>'Browser QA Client','email'=>'browser.client@example.test',
    'password'=>'BrowserOnlyPassword123!','role'=>'customer',
]);
$creator=\App\Models\User::query()->create([
    'name'=>'Browser QA Creator','email'=>'browser.creator@example.test',
    'password'=>'BrowserOnlyPassword123!','role'=>'freelancer',
]);
\App\Models\CreatorProfile::query()->create([
    'user_id'=>$creator->id,
    'headline'=>'Original full-stack Laravel consulting',
    'biography'=>str_repeat('Original test content for responsive UI verification. ',3),
    'is_public'=>true,
]);
$serviceCategory=\App\Models\MarketplaceCategory::query()->create([
    'type'=>'service',
    'name'=>'Browser QA Consulting Category',
    'description'=>'A real active category for marketplace navigation testing.',
    'is_active'=>true,'sort_order'=>1,'created_by'=>$creator->id,
]);
\App\Models\MarketplaceCategory::query()->create([
    'type'=>'service','name'=>'Hidden Browser QA Category',
    'is_active'=>false,'sort_order'=>2,'created_by'=>$creator->id,
]);
// More than six active categories ensures desktop, tablet and phone sliders
// must expose every link via native scrolling and working arrow controls.
foreach (range(1,12) as $index) {
    \App\Models\MarketplaceCategory::query()->create([
        'type'=>['product','project','service','freelance'][($index-1)%4],
        'name'=>'Browser QA Slider Category '.$index,
        'description'=>'Original isolated browser fixture for the category rail.',
        'is_active'=>true,
        'sort_order'=>$index+5,
        'created_by'=>$creator->id,
    ]);
}
\App\Models\MarketplaceListing::query()->create([
    'user_id'=>$creator->id,'type'=>'service','category_id'=>$serviceCategory->id,
    'title'=>'Browser QA Published Service',
    'description'=>str_repeat('Original service listing for real browser acceptance tests. ',3),
    'status'=>'published','price'=>'95.00','currency'=>'USD',
]);
\App\Models\MarketplaceJob::query()->create([
    'client_id'=>$client->id,
    'title'=>'Browser QA Published Freelance Job',
    'description'=>str_repeat('Realistic approved job for browser smoke checks. ',3),
    'category'=>'development','budget'=>'240.00','currency'=>'USD','status'=>'published',
]);
$admin=\App\Models\User::query()->create([
    'name'=>'Browser QA Admin','email'=>'browser.admin@example.test',
    'password'=>'BrowserOnlyPassword123!','role'=>'super_admin',
]);
// Public-detail visual acceptance fixtures use only original QA descriptions and assets.
foreach ([
    ['project','Browser QA Published Project','project'],
    ['freelance','Browser QA Published Freelance','freelance'],
] as [$type,$title,$name]){
    \App\Models\MarketplaceListing::query()->create([
        'user_id'=>$creator->id,'type'=>$type,
        'title'=>$title,
        'description'=>str_repeat('Original independently published marketplace offer for visual acceptance. ',3),
        'status'=>'published','price'=>'125.00','currency'=>'USD',
    ]);
}
$license=\App\Models\LicenseDefinition::query()->create([
    'code'=>'BROWSER_QA_LICENSE','name'=>'Browser QA Original License',
    'version'=>'1.0.0',
    'permissions'=>str_repeat('This original QA content is for isolated testing only. ',2),
    'restrictions'=>str_repeat('No third-party rights or external sale is included. ',2),
    'status'=>'active','published_at'=>now(),'created_by'=>$admin->id,
]);
$png=base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/l5cAAAAASUVORK5CYII=',
    true
);
if($png===false)throw new RuntimeException('Original test image missing.');
$coverPath='marketplace/previews/'.$creator->id.'/browser-original.png';
\Illuminate\Support\Facades\Storage::disk('local')->put($coverPath,$png);
$cover=\App\Models\MediaAsset::query()->create([
    'user_id'=>$creator->id,'original_name'=>'browser-original.png',
    'storage_path'=>$coverPath,'mime_type'=>'image/png','bytes'=>strlen($png),
    'width'=>1,'height'=>1,'alt_text'=>'Original approved browser acceptance preview',
    'rights_basis'=>'owned','rights_holder'=>'Browser QA Creator',
    'review_status'=>'approved','reviewed_by'=>$admin->id,'reviewed_at'=>now(),
]);
\App\Models\MarketplaceListing::query()->create([
    'user_id'=>$creator->id,'type'=>'product','title'=>'Browser QA Published Product',
    'description'=>str_repeat('Original licensed digital product for responsive QA acceptance. ',3),
    'license_definition_id'=>$license->id,'cover_media_id'=>$cover->id,
    'license_terms'=>'No real commercial rights or payments are transferred.',
    'status'=>'published','price'=>'45.00','currency'=>'USD',
]);
$terms=\App\Models\Policy::query()->create([
    'title'=>'Browser QA Terms of Service',
    'category'=>'terms','status'=>'published','jurisdiction'=>'Test environment',
    'body'=>str_repeat('A test-only published policy used to exercise required registration acceptance. ',3),
    'reviewed_by'=>$admin->id,'reviewed_at'=>now(),
]);
\App\Models\PolicyPublication::query()->create([
    'policy_id'=>$terms->id,'revision'=>1,
    'title'=>$terms->title,'category'=>'terms','jurisdiction'=>'Test environment',
    'body_snapshot'=>$terms->body,
    'sha256'=>\App\Models\PolicyPublication::digestFor($terms),
    'published_by'=>$admin->id,'created_at'=>now(),
]);
// Published knowledge fixtures are strictly disposable browser test records.
foreach ([
    ['Browser QA Getting Started Guide', 'getting-started', 'This original short guide explains how to create an account and navigate your dashboard.'],
    ['Browser QA Product License Guide', 'licenses', 'Learn how to review your license details and find product access information.'],
] as [$title, $category, $excerpt]) {
    \App\Models\KnowledgeArticle::query()->create([
        'title' => $title, 'category' => $category, 'excerpt' => $excerpt,
        'body' => str_repeat('Use the QA guidance to review your account securely. ', 3),
        'body_format' => 'markdown', 'status' => 'published',
        'author_id' => $admin->id, 'reviewed_by' => $admin->id, 'published_at' => now(),
    ]);
}
echo "Public browser fixtures, including test-only published Terms, seeded in an isolated test database.\n";
