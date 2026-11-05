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
echo "Public browser fixtures, including test-only published Terms, seeded in an isolated test database.\n";
