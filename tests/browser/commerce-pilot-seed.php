<?php

declare(strict_types=1);

use App\Models\CommercePaymentMethod;
use App\Models\LicenseDefinition;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\Policy;
use App\Models\PolicyPublication;
use App\Models\ProductFileVersion;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

$base = dirname(__DIR__, 2);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || getenv('BAMBODIA_UAT_ISOLATED') !== '1'
    || config('app.url') !== 'http://127.0.0.1:8097'
    || ! config('commerce.enabled') || ! config('commerce.payments_enabled') || config('commerce.live_gateways')) {
    fwrite(STDERR, "Commerce pilot requires an isolated loopback test environment with live gateways off.\n");
    exit(2);
}

$isolatedDatabase = match (config('database.default')) {
    'mysql' => config('database.connections.mysql.database') === 'bambodia_uat'
        && in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)
        && User::query()->where('email', 'uat.super@example.test')->where('role', 'super_admin')->exists(),
    'sqlite' => realpath(config('database.connections.sqlite.database')) === realpath($base.'/storage/framework/commerce-pilot.sqlite')
        && is_file($base.'/storage/framework/commerce-pilot.sqlite') && ! User::query()->exists(),
    default => false,
};
if (! $isolatedDatabase) {
    fwrite(STDERR, "Invalid database for the commerce pilot.\n");
    exit(2);
}

$users = [];
foreach (['buyer' => 'customer', 'seller' => 'freelancer', 'admin' => 'super_admin', 'outsider' => 'customer'] as $name => $role) {
    $users[$name] = User::query()->create([
        'name' => 'Pilot '.ucfirst($name), 'email' => 'commerce-pilot.'.$name.'@example.test',
        'password' => 'CommercePilotBrowserOnly123!', 'role' => $role,
    ]);
}
$seller = $users['seller'];
$admin = $users['admin'];
$users['buyer']->markEmailAsVerified();
$terms = Policy::query()->create([
    'title' => 'Terms of Service', 'category' => 'terms', 'status' => 'published',
    'public_code' => '91204896', 'body' => 'Synthetic publication for the isolated checkout pilot.',
]);
PolicyPublication::query()->create([
    'policy_id' => $terms->id, 'revision' => 1, 'title' => $terms->title, 'category' => 'terms',
    'body_snapshot' => $terms->body, 'sha256' => PolicyPublication::digestFor($terms),
    'published_by' => $admin->id, 'created_at' => now(),
]);
Policy::query()->create([
    'title' => 'Privacy Policy', 'category' => 'privacy', 'status' => 'published',
    'public_code' => '21879964', 'body' => 'Synthetic privacy notice for the isolated checkout pilot.',
]);
$license = LicenseDefinition::query()->create([
    'name' => 'Pilot single-site license', 'code' => 'COMMERCE_PILOT', 'version' => '1.0.0', 'tier' => 'regular',
    'permissions' => 'Use the template for one site in this isolated pilot.',
    'restrictions' => 'Pilot-only offer. The browser test does not establish commercial terms.',
    'created_by' => $admin->id, 'status' => 'active', 'published_at' => now(),
]);

$receipt = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl+R1gAAAAASUVORK5CYII=', true);
$coverPath = 'marketplace/previews/'.$seller->id.'/commerce-pilot.png';
Storage::disk('local')->put($coverPath, $receipt);
$cover = MediaAsset::query()->create([
    'user_id' => $seller->id, 'original_name' => 'commerce-pilot.png', 'storage_path' => $coverPath,
    'mime_type' => 'image/png', 'bytes' => strlen($receipt), 'width' => 1, 'height' => 1, 'alt_text' => 'Pilot product cover',
    'rights_basis' => 'owned', 'rights_holder' => $seller->name, 'review_status' => 'approved',
    'reviewed_by' => $admin->id, 'reviewed_at' => now(),
]);
$product = MarketplaceListing::query()->create([
    'user_id' => $seller->id, 'type' => 'product', 'title' => 'River Market Restaurant Website Template',
    'description' => 'A responsive HTML template with a menu, opening hours and contact section.',
    'status' => 'published', 'price' => '29.00', 'regular_price' => '29.00', 'currency' => 'USD',
    'cover_media_id' => $cover->id, 'license_definition_id' => $license->id,
]);
$service = MarketplaceListing::query()->create([
    'user_id' => $seller->id, 'type' => 'service', 'title' => 'Restaurant Website Installation and Handoff',
    'description' => 'Install one template and deliver a deployment checklist.',
    'status' => 'published', 'price' => '75.00', 'currency' => 'USD',
]);

$zipFile = $base.'/storage/framework/commerce-pilot-template.zip';
$zip = new ZipArchive;
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Could not create pilot archive.');
}
$zip->addFromString('index.html', <<<'HTML'
<!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>River Market Kitchen</title>
<style>
:root{font-family:system-ui,sans-serif;color:#263529;background:#f6f1e5}
body{margin:0}header,main,footer{padding:clamp(1.25rem,6vw,5rem)}
header,footer{background:#193b2a;color:#fff}nav a{display:inline-block;color:inherit;margin-right:1rem}
h1{font-size:clamp(2.5rem,7vw,5rem);line-height:1;margin:1.25rem 0}
.menu{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(14rem,100%),1fr));gap:1rem}
.menu article{background:white;padding:1.25rem;border-radius:1rem}
</style>
<header><nav aria-label="Sections"><a href="#menu">Menu</a><a href="#hours">Hours</a></nav>
<h1>River Market Kitchen</h1><p>Seasonal plates and Cambodian comfort food.</p></header>
<main><section id="menu"><h2>Today’s menu</h2><div class="menu">
<article><h3>Grilled lemongrass chicken</h3><p>Served with fragrant rice and cucumber.</p></article>
<article><h3>Mushroom and herb noodles</h3><p>Tossed with garlic and fresh basil.</p></article>
</div></section><section id="hours"><h2>Opening hours</h2><p>Tuesday to Sunday, 11 am to 9 pm.</p></section></main>
<footer><a href="mailto:hello@example.test">Contact the kitchen</a></footer>
</html>
HTML);
$zip->addFromString('README.txt', "Open index.html in any modern browser. Update the menu, hours and address before publishing.\n");
if (! $zip->close()) {
    throw new RuntimeException('Could not finish pilot archive.');
}
$hash = hash_file('sha256', $zipFile);
$path = "marketplace/originals/{$seller->id}/{$product->id}/".$hash.'.zip';
Storage::disk('local')->put($path, file_get_contents($zipFile));
$release = ProductFileVersion::query()->create([
    'marketplace_listing_id' => $product->id, 'uploaded_by' => $seller->id, 'version' => '1.0',
    'original_filename' => 'river-market-template.zip', 'release_notes' => 'Self-contained browser-test template.',
    'storage_path' => $path, 'file_type' => 'zip', 'byte_size' => filesize($zipFile), 'sha256' => $hash,
    'status' => 'approved', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'rights_attested_at' => now(),
    'rights_evidence' => 'Original HTML authored for this isolated fixture.', 'rights_review_status' => 'verified',
    'rights_reviewed_by' => $admin->id, 'rights_reviewed_at' => now(), 'rights_review_note' => 'Fixture metadata only.',
    'malware_scan_status' => 'clean', 'malware_scanned_sha256' => $hash, 'malware_scan_engine' => 'ClamAV isolated fixture metadata',
    'malware_scanned_at' => now(),
]);
if (! $release->isApproved()) {
    throw new RuntimeException('Pilot fixture must satisfy the purchase release checks.');
}
CommercePaymentMethod::query()->where('provider', 'bank_transfer')->sole()->update([
    'available' => true, 'instructions' => 'Isolated pilot. Upload a sample receipt. Do not send money.',
]);
file_put_contents($base.'/storage/framework/commerce-pilot.json', json_encode([
    'product_url' => $product->publicUrl(), 'service_url' => $service->publicUrl(),
    'sha256' => $hash, 'receipt' => base64_encode($receipt),
], JSON_THROW_ON_ERROR));
echo "Commerce pilot catalog and role accounts seeded.\n";
