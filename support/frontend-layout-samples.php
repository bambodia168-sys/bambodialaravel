<?php
/**
 * One-time UI layout fixtures for the owner's Bambodia installation.
 * No application code, migrations, user orders or existing listings are modified.
 * Run under root with the site's PHP 8.5 binary after taking a full database backup.
 */
declare(strict_types=1);

use App\Models\FeatureFlag;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function stopSample(string $message): never {
    fwrite(STDERR, "FRONTEND_SAMPLE_SEED=BLOCKED: $message\n");
    exit(1);
}

const QA_APP = '/www/wwwroot/bambodia-staging-qa';
const QA_PREFIX = '[LAYOUT TEST] ';
const QA_CREATOR = 'layout-preview-creator@bambodia.invalid';
const QA_CLIENT = 'layout-preview-client@bambodia.invalid';

$mode = $argv[1] ?? '';
if (!in_array($mode, ['--check', '--apply'], true)) {
    stopSample('Usage: php support/frontend-layout-samples.php --check | --apply /root/backup.sql.gz');
}
if ($mode === '--check' && count($argv) !== 2) {
    stopSample('--check accepts no additional arguments.');
}
if ($mode === '--apply' && count($argv) !== 3) {
    stopSample('--apply requires a preexisting private MySQL .sql.gz snapshot as second argument.');
}
if (PHP_SAPI !== 'cli' || function_exists('posix_geteuid') && posix_geteuid() !== 0) {
    stopSample('Run as root from the verified GitHub source checkout.');
}
if (realpath(__DIR__) !== '/root/bambodia-release-audit/support') {
    stopSample('Unexpected source path; refusing to touch any database.');
}
$backup = $argv[2] ?? null;
if ($mode === '--apply') {
    if (!is_string($backup) || !str_starts_with($backup, '/root/')
        || !str_ends_with($backup, '.sql.gz') || !is_file($backup)
        || is_link($backup) || filesize($backup) < 100) {
        stopSample('A real, nonempty MySQL snapshot under /root is required.');
    }
}
if (!is_file(QA_APP.'/.env') || !is_file(QA_APP.'/storage/app/installed.lock')
    || realpath(QA_APP) !== QA_APP || is_link(QA_APP)) {
    stopSample('Installed site identity did not match the verified Bambodia application.');
}
require QA_APP.'/vendor/autoload.php';
$app = require QA_APP.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (!app()->environment('production') ||
    rtrim((string) config('app.url'), '/') !== 'https://bambodia.com' ||
    config('database.default') !== 'mysql' ||
    (is_file(storage_path('framework/down')) && getenv('BAMBODIA_LAYOUT_DEPLOY') !== '1')) {
    stopSample('Production domain, MySQL or application state does not match expected deployment.');
}
foreach (['users','marketplace_listings','marketplace_jobs','marketplace_categories','media_assets','license_definitions'] as $table) {
    if (!Schema::hasTable($table)) {
        stopSample('Missing existing migration/table: '.$table);
    }
}
if (!FeatureFlag::isEnabled('marketplace_search')) {
    stopSample('Marketplace search is disabled; preview entries cannot be displayed.');
}
$admin = User::query()->where('role','super_admin')->orderBy('id')->first();
if (!$admin) {
    stopSample('Cannot create administrator-reviewed test media without an existing super_admin.');
}

$items = [
    'product' => [
        ['Responsive Portfolio UI Kit','An example portfolio template with mobile navigation, project cards and typography.',29],
        ['Marketplace Dashboard Template','An illustrative admin workspace with statistics, data tables and filters.',49],
        ['Restaurant Landing Page Design','A hospitality-themed landing page showing menu sections and image galleries.',35],
        ['Education LMS Interface Pack','Example learning interface with course grids, lessons and dashboard summaries.',59],
        ['Real Estate Property UI Bundle','A property catalog concept with listing cards, search and detail layouts.',45],
    ],
    'project' => [
        ['Creative Agency Website Concept','A portfolio project demonstrating brand pages and responsive navigation.',900],
        ['Community Learning Platform','A concept project for a course directory and student progress workspace.',1200],
        ['Local Marketplace Website Refresh','A site redesign case study focused on search, listing cards and usability.',780],
        ['Eco Tourism Website Showcase','A project portfolio highlighting guide cards, destinations and photo galleries.',650],
        ['Small Business CRM Demonstration','An illustrative CRM project featuring contacts, tasks and overview reports.',980],
    ],
    'service' => [
        ['Website Design Consultation','An illustrative consulting offer covering planning and layout review.',65],
        ['Brand Identity and Logo Design','An example branding service with a project brief and visual exploration.',120],
        ['Website Accessibility Review','An example service describing accessibility, contrast and keyboard navigation.',90],
        ['SEO Content Structure Audit','An illustrative content planning service covering headings and page structure.',75],
        ['Responsive UI and UX Evaluation','A sample design review service covering phone, tablet and desktop layouts.',85],
    ],
    'freelance' => [
        ['Laravel Application Developer','A fictional freelancer profile highlighting backend development services.',40],
        ['Frontend UI Designer','An illustrative freelance service for responsive component and layout design.',35],
        ['Business Presentation Designer','A fictional creator offer for pitch decks and visual business reporting.',28],
        ['English and Khmer Content Writer','A sample writing profile for website text, articles and communication.',22],
        ['Digital Marketing Campaign Planner','An example freelancer offering planning, creative calendars and reporting.',32],
    ],
];
$jobs = [
    ['Build a Responsive Business Website','development',450,'Build a mobile-friendly homepage with clear sections and tested navigation.'],
    ['Design Brand Identity Guidelines','design',350,'Design a basic brand system with typography and identity components.'],
    ['Write Website Service Pages','writing',220,'Prepare concise headings and descriptive service-page content.'],
    ['Plan a Social Media Content Calendar','marketing',300,'Outline a campaign calendar with audience segments and content themes.'],
    ['Organize an Operations Reporting Dashboard','business',550,'Design a KPI summary and management reporting layout for a small team.'],
];
$expectedTitles = [];
foreach ($items as $type => $rows) {
    foreach ($rows as $row) {
        $expectedTitles[$type][] = QA_PREFIX.$row[0];
    }
}
foreach ($jobs as $row) {
    $expectedTitles['job'][] = QA_PREFIX.$row[0];
}
$counts = [];
$publicCounts = [];
foreach ($items as $type => $_) {
    $counts[$type] = MarketplaceListing::query()->where('type',$type)
        ->where('title','like',QA_PREFIX.'%')->count();
    $publicCounts[$type] = MarketplaceListing::query()->publiclyAvailable()->where('type',$type)
        ->where('title','like',QA_PREFIX.'%')->count();
}
$counts['job'] = MarketplaceJob::query()->where('title','like',QA_PREFIX.'%')->count();
$publicCounts['job'] = MarketplaceJob::query()->where('title','like',QA_PREFIX.'%')
    ->where('status','published')->count();
foreach ($counts as $type => $count) {
    printf("%s_EXISTING=%d\n",strtoupper($type),$count);
}
$all = array_sum($counts);
if ($all !== 0 && ($all !== 25 || count(array_filter($publicCounts,fn($n)=>$n===5)) !== 5)) {
    stopSample('Partial LAYOUT TEST data detected; no records changed.');
}
$creator = User::query()->where('email', QA_CREATOR)->first();
$client = User::query()->where('email', QA_CLIENT)->first();
if ($all === 25) {
    if (!$creator || !$client || $creator->role !== 'freelancer' || $client->role !== 'customer') {
        stopSample('Test data belongs to unknown accounts.');
    }
    foreach ($expectedTitles as $type => $names) {
        foreach ($names as $name) {
            $check = $type === 'job'
                ? MarketplaceJob::query()->where('title',$name)->where('client_id',$client->id)
                : MarketplaceListing::query()->where('type',$type)->where('title',$name)->where('user_id',$creator->id);
            if ($check->count() !== 1) {
                stopSample('A test title has unexpected ownership or duplication: '.$name);
            }
        }
    }
    echo "FRONTEND_SAMPLE_SEED=ALREADY_COMPLETE\n";
    echo "PUBLIC_COUNTS=product:5,project:5,service:5,freelance:5,job:5\n";
    exit(0);
}
if ($creator || $client ||
    LicenseDefinition::query()->where('code','LAYOUT_TEST_ONLY')->exists() ||
    MarketplaceCategory::query()->where('name','like','Layout Preview %')->exists()) {
    stopSample('Reserved test identities or categories exist without the 25 records; investigate manually.');
}
if ($mode === '--check') {
    echo "FRONTEND_SAMPLE_PREFLIGHT=PASS\n";
    echo "NEW_RECORDS_PLANNED=25\n";
    echo "PRODUCTION_WRITES=NONE\n";
    exit(0);
}
if (!function_exists('gzcompress')) {
    stopSample('zlib must be available for original PNG test thumbnails.');
}

function samplePng(int $number): string {
    $palette = [[18,78,136],[109,58,149],[19,123,115],[170,80,53],[87,81,158]][$number % 5];
    $raw = '';
    for ($y=0;$y<320;$y++) {
        $row = "\x00";
        for ($x=0;$x<640;$x++) {
            $card = $x>70 && $x<570 && $y>48 && $y<276;
            $heading = $card && $x>105 && $x<420 && $y>89 && $y<117;
            $tile = $card && $y>155 && $y<245 &&
                (($x>105 && $x<230) || ($x>248 && $x<372) || ($x>390 && $x<515));
            $color = $heading ? [207,220,236] : ($tile ? [219,227,239] :
                ($card ? [248,250,253] : [
                    max(0,$palette[0]-intdiv($y,22)),
                    max(0,$palette[1]-intdiv($y,23)),
                    max(0,$palette[2]-intdiv($y,24)),
                ]));
            $row .= chr($color[0]).chr($color[1]).chr($color[2]);
        }
        $raw .= $row;
    }
    $chunk=static fn(string $tag,string $data): string =>
        pack('N',strlen($data)).$tag.$data.pack('N',crc32($tag.$data));
    return "\x89PNG\r\n\x1a\n".$chunk('IHDR',pack('NNCCCCC',640,320,8,2,0,0,0))
        .$chunk('IDAT',gzcompress($raw,8)).$chunk('IEND','');
}
$colors = ['project'=>'#375d91','service'=>'#147970','freelance'=>'#7951ac'];
$written = [];
$disk = Storage::disk('local');
try {
    DB::transaction(function () use ($admin,$items,$jobs,$disk,&$written,$colors) {
        $creator=User::query()->create([
            'name'=>'Bambodia Layout Preview (Demo Creator)','email'=>QA_CREATOR,
            'password'=>Str::random(80),'role'=>'freelancer','locale'=>'en',
        ]);
        $client=User::query()->create([
            'name'=>'Bambodia Layout Preview (Demo Client)','email'=>QA_CLIENT,
            'password'=>Str::random(80),'role'=>'customer','locale'=>'en',
        ]);
        $license=LicenseDefinition::query()->create([
            'code'=>'LAYOUT_TEST_ONLY','tier'=>'regular',
            'name'=>'Layout Test Only — No Commercial License',
            'version'=>'1.0.0','created_by'=>$admin->id,
            'permissions'=>'This preview is displayed only for UI layout evaluation. No purchase, download or commercial rights are granted.',
            'restrictions'=>'No sale, license issuance, support, distribution, reuse, paid service or other real business transaction is offered.',
            'status'=>'active','published_at'=>now(),
        ]);
        foreach ($items as $type=>$rows) {
            $category=MarketplaceCategory::query()->create([
                'type'=>$type,'name'=>'Layout Preview '.ucfirst($type),
                'description'=>'Clearly labeled fictional items for layout testing only.',
                'is_active'=>true,'sort_order'=>990,'created_by'=>$admin->id,
            ]);
            foreach ($rows as $index=>$row) {
                [$name,$description,$price]=$row;
                $extra=[];
                if ($type==='product') {
                    $path='layout-previews/'.$creator->id.'/layout-preview-'.sprintf('%02d',$index+1).'.png';
                    if ($disk->exists($path)) {
                        throw new RuntimeException('Would overwrite an existing media object.');
                    }
                    $png=samplePng($index);
                    if (!$disk->put($path,$png)) {
                        throw new RuntimeException('Original preview image could not be written.');
                    }
                    $written[]=$path;
                    // The seeder runs as root for protected backups, but PHP-FPM
                    // serves approved media as www. Only adjust our own new paths.
                    $privateRoot = realpath(storage_path('app/private'));
                    $previewDir = $disk->path('layout-previews');
                    $ownerDir = $disk->path('layout-previews/'.$creator->id);
                    foreach ([$previewDir,$ownerDir] as $directory) {
                        if (!$privateRoot || !str_starts_with($directory,$privateRoot.'/')
                            || !is_dir($directory)
                            || !chown($directory,'www') || !chgrp($directory,'www')
                            || !chmod($directory,0750)) {
                            throw new RuntimeException('Could not secure preview directory for www.');
                        }
                    }
                    $absolutePath = $disk->path($path);
                    if (!chown($absolutePath,'www') || !chgrp($absolutePath,'www')
                        || !chmod($absolutePath,0640)) {
                        throw new RuntimeException('Could not assign private preview image access.');
                    }
                    $asset=MediaAsset::query()->create([
                        'user_id'=>$creator->id,'original_name'=>'layout-preview-'.sprintf('%02d',$index+1).'.png',
                        'storage_path'=>$path,'storage_disk'=>'local',
                        'mime_type'=>'image/png','bytes'=>strlen($png),
                        'width'=>640,'height'=>320,'alt_text'=>'Generated UI layout sample: '.$name,
                        'rights_basis'=>'owned','rights_holder'=>'Bambodia Layout Preview',
                        'rights_note'=>'Original generated geometric UI sample, not a product for sale.',
                        'review_status'=>'approved','review_note'=>'Original test artwork only',
                        'reviewed_by'=>$admin->id,'reviewed_at'=>now(),
                    ]);
                    $extra=[
                        'license_definition_id'=>$license->id,'cover_media_id'=>$asset->id,
                        'featured_kind'=>'image','featured_media_id'=>$asset->id,
                        'license_terms'=>'LAYOUT TEST ONLY. Not for sale. No real license, purchase, download, product support or transaction is available.',
                    ];
                } else {
                    $extra=['featured_kind'=>'color','featured_color'=>$colors[$type]];
                }
                MarketplaceListing::query()->create([
                    'user_id'=>$creator->id,'category_id'=>$category->id,
                    'type'=>$type,'title'=>QA_PREFIX.$name,
                    'description'=>'LAYOUT TEST ONLY — a fictional example for responsive website testing. This is not a real product, service, creator offer or commercial engagement. '.$description,
                    'price'=>$price,'currency'=>'USD','status'=>'published',
                    ...$extra,
                ]);
            }
        }
        foreach ($jobs as $job) {
            [$name,$category,$budget,$description]=$job;
            MarketplaceJob::query()->create([
                'client_id'=>$client->id,'title'=>QA_PREFIX.$name,
                'description'=>'LAYOUT TEST ONLY — fictional job for layout and filter testing. No actual paid work exists; please do not submit a proposal. '.$description,
                'category'=>$category,'budget'=>$budget,'currency'=>'USD',
                'status'=>'published','reviewed_by'=>$admin->id,'reviewed_at'=>now(),
                'featured_kind'=>'color','featured_color'=>'#b57426',
            ]);
        }
    }, 1);
} catch (Throwable $error) {
    foreach ($written as $path) {
        $disk->delete($path);
    }
    stopSample('Database transaction rolled back: '.$error->getMessage());
}
foreach (array_keys($items) as $type) {
    $n=MarketplaceListing::query()->publiclyAvailable()
        ->where('type',$type)->where('title','like',QA_PREFIX.'%')->count();
    printf("PUBLIC_%s=%d\n",strtoupper($type),$n);
    if ($n!==5) {
        stopSample('Unexpected published listing count after insertion.');
    }
}
$jobCount=MarketplaceJob::query()->where('title','like',QA_PREFIX.'%')->where('status','published')->count();
echo "PUBLIC_JOB=$jobCount\n";
if ($jobCount!==5) {
    stopSample('Unexpected job count after insertion.');
}
echo "FRONTEND_SAMPLE_SEED=SUCCESS\nCREATED_LISTINGS=20\nCREATED_JOBS=5\n";
echo "SAMPLES_ARE_FICTIONAL=YES\nNO_EXISTING_LISTINGS_CHANGED=YES\n";
