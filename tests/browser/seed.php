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
\App\Models\MarketplaceListing::query()->create([
    'user_id'=>$creator->id,'type'=>'service',
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
echo "Public browser fixtures seeded in an isolated test database.\n";
