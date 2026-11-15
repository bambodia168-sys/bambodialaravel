<?php

declare(strict_types=1);

use App\Models\AuditEvent;
use App\Models\MarketplaceCategory;
use App\Models\MarketplaceJob;
use App\Models\MarketplaceListing;
use App\Models\MediaAsset;
use App\Models\User;
use App\Support\BambodiaTaxonomy;
use App\Support\BuilderFeaturedMedia;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

const APPLICATION = '/www/wwwroot/bambodia-staging-qa';
const OWNER_ID = 1;
const MANIFEST = <<<'JSON'
[
{"type":"product","title":"Laravel Real Estate Marketplace Script","category":"PHP Scripts","filename":"01-laravel-real-estate-marketplace-script.jpg","media_id":34,"media_public_id":"419977228","listing_id":21,"listing_public_id":"628298628"},
{"type":"product","title":"WordPress Theme for Hotels and Guesthouses","category":"WordPress Themes","filename":"02-wordpress-theme-for-hotels-and-guesthouses.jpg","media_id":35,"media_public_id":"367133727","listing_id":23,"listing_public_id":"696871730"},
{"type":"product","title":"WordPress Appointment Booking Plugin","category":"WordPress Plugins","filename":"03-wordpress-appointment-booking-plugin.jpg","media_id":36,"media_public_id":"402683678","listing_id":25,"listing_public_id":"213030191"},
{"type":"product","title":"Responsive Restaurant Website Template","category":"Website Templates","filename":"04-responsive-restaurant-website-template.jpg","media_id":37,"media_public_id":"386418392","listing_id":24,"listing_public_id":"657888435"},
{"type":"product","title":"Flutter Grocery Delivery App Template","category":"Mobile App Templates","filename":"05-flutter-grocery-delivery-app-template.jpg","media_id":38,"media_public_id":"341616514","listing_id":22,"listing_public_id":"548876546"},
{"type":"product","title":"Figma UI Kit for School Management Dashboards","category":"UI Kits & Design Assets","filename":"06-figma-ui-kit-for-school-management-dashboards.jpg","media_id":39,"media_public_id":"452627928","listing_id":26,"listing_public_id":"278584018"},
{"type":"product","title":"Editable Social Media Templates for Online Stores","category":"Graphics & Illustrations","filename":"07-editable-social-media-templates-for-online-stores.jpg","media_id":40,"media_public_id":"584548299","listing_id":28,"listing_public_id":"619022255"},
{"type":"product","title":"Background Music Pack for Business Videos","category":"Audio & Music","filename":"08-background-music-pack-for-business-videos.jpg","media_id":41,"media_public_id":"185193800","listing_id":29,"listing_public_id":"568652970"},
{"type":"product","title":"Small Business Plan and Budget Template Bundle","category":"E-books & Documents","filename":"09-small-business-plan-and-budget-template-bundle.jpg","media_id":42,"media_public_id":"111841090","listing_id":27,"listing_public_id":"946410080"},
{"type":"product","title":"Laravel KHQR Payment Integration Package","category":"Plugins & Extensions","filename":"10-laravel-khqr-payment-integration-package.jpg","media_id":43,"media_public_id":"877349682","listing_id":30,"listing_public_id":"908085193"},
{"type":"project","title":"Build a Property Listing Website for a Real Estate Agency","category":"Website Development","filename":"01-build-a-property-listing-website-for-a-real-estate-agency.jpg","media_id":44,"media_public_id":"277867106","listing_id":31,"listing_public_id":"239244381"},
{"type":"project","title":"Develop an Android and iOS Appointment Booking App","category":"Mobile App Development","filename":"02-develop-an-android-and-ios-appointment-booking-app.jpg","media_id":45,"media_public_id":"253983362","listing_id":33,"listing_public_id":"624062483"},
{"type":"project","title":"Build an Online Clothing Store with KHQR Payments","category":"E-commerce Development","filename":"03-build-an-online-clothing-store-with-khqr-payments.jpg","media_id":46,"media_public_id":"507817281","listing_id":32,"listing_public_id":"650796404"},
{"type":"project","title":"Design a Student Dashboard for an Online Learning Platform","category":"UI/UX Design","filename":"04-design-a-student-dashboard-for-an-online-learning-platform.jpg","media_id":47,"media_public_id":"992100917","listing_id":35,"listing_public_id":"313122137"},
{"type":"project","title":"Create a Logo and Brand Identity for a Coffee Shop","category":"Branding & Graphic Design","filename":"05-create-a-logo-and-brand-identity-for-a-coffee-shop.jpg","media_id":48,"media_public_id":"497431637","listing_id":34,"listing_public_id":"757850809"},
{"type":"project","title":"Plan and Launch a Facebook Campaign for a Training Center","category":"Digital Marketing Campaigns","filename":"06-plan-and-launch-a-facebook-campaign-for-a-training-center.jpg","media_id":49,"media_public_id":"134260442","listing_id":37,"listing_public_id":"677667282"},
{"type":"project","title":"Produce a 60-Second Product Explainer Video","category":"Video & Animation Production","filename":"07-produce-a-60-second-product-explainer-video.jpg","media_id":50,"media_public_id":"575083431","listing_id":36,"listing_public_id":"353498079"},
{"type":"project","title":"Translate a Company Website from English into Khmer","category":"Content Writing & Translation","filename":"08-translate-a-company-website-from-english-into-khmer.jpg","media_id":51,"media_public_id":"680718817","listing_id":40,"listing_public_id":"564399174"},
{"type":"project","title":"Build a Customer Support Chatbot for an Online Store","category":"AI & Automation Development","filename":"09-build-a-customer-support-chatbot-for-an-online-store.jpg","media_id":52,"media_public_id":"336030953","listing_id":39,"listing_public_id":"567842877"},
{"type":"project","title":"Analyze Customer Survey Data and Prepare a Findings Report","category":"Data Analysis & Research","filename":"10-analyze-customer-survey-data-and-prepare-a-findings-report.jpg","media_id":53,"media_public_id":"268247688","listing_id":38,"listing_public_id":"585099724"},
{"type":"service","title":"Laravel Script Installation and Configuration on aaPanel","category":"Website Installation & Setup","filename":"01-laravel-script-installation-and-configuration-on-aapanel.jpg","media_id":54,"media_public_id":"353694275","listing_id":41,"listing_public_id":"441696845"},
{"type":"service","title":"Monthly WordPress Updates and Technical Support","category":"Website Maintenance & Support","filename":"02-monthly-wordpress-updates-and-technical-support.jpg","media_id":55,"media_public_id":"152269694","listing_id":42,"listing_public_id":"924081401"},
{"type":"service","title":"VPS Setup with Nginx, PHP, MySQL, and SSL","category":"Hosting & Server Management","filename":"03-vps-setup-with-nginx-php-mysql-and-ssl.jpg","media_id":56,"media_public_id":"778332767","listing_id":43,"listing_public_id":"687551186"},
{"type":"service","title":"Domain Transfer and DNS Configuration","category":"Domain Registration & Transfer","filename":"04-domain-transfer-and-dns-configuration.jpg","media_id":57,"media_public_id":"535755073","listing_id":44,"listing_public_id":"986635215"},
{"type":"service","title":"Technical SEO Audit and On-Page Optimization for 10 Pages","category":"SEO Optimization","filename":"05-technical-seo-audit-and-on-page-optimization-for-10-pages.jpg","media_id":58,"media_public_id":"497113200","listing_id":45,"listing_public_id":"737368223"},
{"type":"service","title":"Monthly Facebook Page Management with 12 Posts","category":"Social Media Management","filename":"06-monthly-facebook-page-management-with-12-posts.jpg","media_id":59,"media_public_id":"868546914","listing_id":46,"listing_public_id":"722084365"},
{"type":"service","title":"Facebook and Instagram Ad Campaign Setup and Management","category":"Advertising Campaign Management","filename":"07-facebook-and-instagram-ad-campaign-setup-and-management.jpg","media_id":60,"media_public_id":"917797798","listing_id":48,"listing_public_id":"831201191"},
{"type":"service","title":"WordPress Malware Removal and Security Hardening","category":"Website Security & Malware Removal","filename":"08-wordpress-malware-removal-and-security-hardening.jpg","media_id":61,"media_public_id":"690373236","listing_id":49,"listing_public_id":"385620029"},
{"type":"service","title":"Website Migration from cPanel to aaPanel with Backup","category":"Website Migration & Backup","filename":"09-website-migration-from-cpanel-to-aapanel-with-backup.jpg","media_id":62,"media_public_id":"109620321","listing_id":47,"listing_public_id":"963164437"},
{"type":"service","title":"Business Plan Review and a 60-Minute Strategy Consultation","category":"Business Consulting","filename":"10-business-plan-review-and-a-60-minute-strategy-consultation.jpg","media_id":63,"media_public_id":"945264425","listing_id":50,"listing_public_id":"309240756"},
{"type":"job","title":"Full-Time Laravel Developer","category":"Software Development","filename":"01-full-time-laravel-developer.jpg","media_id":24,"media_public_id":"427205166","listing_id":null,"description":"This listing draft describes a full-time Laravel developer role involving feature development, maintenance, testing and collaboration. Employer details, location, compensation and application instructions are pending confirmation."},
{"type":"job","title":"Graphic Designer for Digital and Print Marketing","category":"Design & Creative","filename":"02-graphic-designer-for-digital-and-print-marketing.jpg","media_id":25,"media_public_id":"233241361","listing_id":null,"description":"This listing draft describes a graphic design role covering digital campaign assets, print layouts and production files. Employer details, work arrangement, compensation and the application process are pending confirmation."},
{"type":"job","title":"Digital Marketing and Social Media Executive","category":"Marketing & Communications","filename":"03-digital-marketing-and-social-media-executive.jpg","media_id":26,"media_public_id":"886228877","listing_id":null,"description":"This listing draft describes a role covering digital campaigns, social media planning and performance reporting. Employer details, employment terms, compensation and application instructions are pending confirmation."},
{"type":"job","title":"Business Development Executive for Web Services","category":"Sales & Business Development","filename":"04-business-development-executive-for-web-services.jpg","media_id":27,"media_public_id":"911428237","listing_id":null,"description":"This listing draft describes a business development role focused on web services, client discovery and proposal follow-up. Employer details, terms, compensation and application instructions are pending confirmation."},
{"type":"job","title":"Khmer and English Customer Support Officer","category":"Customer Service & Support","filename":"05-khmer-and-english-customer-support-officer.jpg","media_id":28,"media_public_id":"146353266","listing_id":null,"description":"This listing draft describes a support role answering customer inquiries in Khmer and English and escalating issues when needed. Employer details, hours, compensation and application instructions are pending confirmation."},
{"type":"job","title":"Accountant for a Retail Business","category":"Finance & Accounting","filename":"06-accountant-for-a-retail-business.jpg","media_id":29,"media_public_id":"642701168","listing_id":null,"description":"This listing draft describes a retail accounting role covering transaction records, reconciliations and reporting. Employer details, qualifications, compensation and application instructions are pending confirmation."},
{"type":"job","title":"Human Resources and Recruitment Officer","category":"Human Resources & Recruitment","filename":"07-human-resources-and-recruitment-officer.jpg","media_id":30,"media_public_id":"633843603","listing_id":null,"description":"This listing draft describes an HR role covering recruitment coordination, interview scheduling and employee records. Employer details, compensation and application instructions are pending confirmation."},
{"type":"job","title":"Office Administration and Operations Coordinator","category":"Administration & Operations","filename":"08-office-administration-and-operations-coordinator.jpg","media_id":31,"media_public_id":"695206367","listing_id":null,"description":"This listing draft describes an office coordination role covering schedules, records and day-to-day operations. Employer details, work arrangement, compensation and application instructions are pending confirmation."},
{"type":"job","title":"English Language Teacher for Primary Students","category":"Education & Training","filename":"09-english-language-teacher-for-primary-students.jpg","media_id":32,"media_public_id":"829345232","listing_id":null,"description":"This listing draft describes a role teaching English to primary students, planning age-appropriate lessons and tracking progress. School details, working hours, compensation and application instructions are pending confirmation."},
{"type":"job","title":"IT Support Technician for a School Campus","category":"IT & Technical Support","filename":"10-it-support-technician-for-a-school-campus.jpg","media_id":33,"media_public_id":"458930996","listing_id":null,"description":"This listing draft describes a school IT support role covering campus hardware, software, classroom connectivity and issue tracking. School details, compensation and application instructions are pending confirmation."},
{"type":"freelance","title":"Laravel Developer for Business Websites and Web Applications","category":"Web Developers","filename":"01-laravel-developer-for-business-websites-and-web-applications.jpg","media_id":14,"media_public_id":"195974741","listing_id":53,"listing_public_id":"349965609"},
{"type":"freelance","title":"Flutter Developer for Android and iOS Applications","category":"Mobile App Developers","filename":"02-flutter-developer-for-android-and-ios-applications.jpg","media_id":15,"media_public_id":"574611109","listing_id":52,"listing_public_id":"441064954"},
{"type":"freelance","title":"Graphic Designer for Logos, Branding, and Marketing Materials","category":"Graphic Designers","filename":"03-graphic-designer-for-logos-branding-and-marketing-materials.jpg","media_id":16,"media_public_id":"698022961","listing_id":51,"listing_public_id":"419411371"},
{"type":"freelance","title":"Figma UI/UX Designer for Websites and Mobile Apps","category":"UI/UX Designers","filename":"04-figma-ui-ux-designer-for-websites-and-mobile-apps.jpg","media_id":17,"media_public_id":"336979529","listing_id":55,"listing_public_id":"461505296"},
{"type":"freelance","title":"Content Writer for Website Pages and Product Descriptions","category":"Writers & Copywriters","filename":"05-content-writer-for-website-pages-and-product-descriptions.jpg","media_id":18,"media_public_id":"912105379","listing_id":54,"listing_public_id":"878315360"},
{"type":"freelance","title":"Digital Marketing Specialist for Facebook Ads and SEO","category":"Digital Marketers","filename":"06-digital-marketing-specialist-for-facebook-ads-and-seo.jpg","media_id":19,"media_public_id":"692088502","listing_id":56,"listing_public_id":"438185080"},
{"type":"freelance","title":"Video Editor for YouTube, Reels, and Promotional Videos","category":"Video Editors & Animators","filename":"07-video-editor-for-youtube-reels-and-promotional-videos.jpg","media_id":20,"media_public_id":"659838234","listing_id":57,"listing_public_id":"691360580"},
{"type":"freelance","title":"English–Khmer Translator for Business and Education Content","category":"Translators & Interpreters","filename":"08-english-khmer-translator-for-business-and-education-content.jpg","media_id":21,"media_public_id":"892085382","listing_id":60,"listing_public_id":"289501697"},
{"type":"freelance","title":"Virtual Assistant for Data Entry and Administrative Support","category":"Virtual Assistants","filename":"09-virtual-assistant-for-data-entry-and-administrative-support.jpg","media_id":22,"media_public_id":"559560139","listing_id":58,"listing_public_id":"998073228"},
{"type":"freelance","title":"Data Analyst for Excel Reports and Business Dashboards","category":"Data Analysts","filename":"10-data-analyst-for-excel-reports-and-business-dashboards.jpg","media_id":23,"media_public_id":"315405453","listing_id":59,"listing_public_id":"645685314"}
]
JSON;

function requireMatch(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/**
 * Resolve the exact uploaded JPEG, category and destination before changing data.
 */
function inspectRow(array $row): array
{
    $asset = MediaAsset::query()->whereKey($row['media_id'])->lockForUpdate()->first();
    requireMatch($asset !== null
        && (int) $asset->user_id === OWNER_ID
        && (string) $asset->public_id === $row['media_public_id']
        && $asset->original_name === $row['filename']
        && $asset->mime_type === 'image/jpeg'
        && in_array($asset->review_status, ['pending', 'approved'], true),
        'Media identity, ownership or review state changed.');

    $category = MarketplaceCategory::query()
        ->where('type', $row['type'])
        ->where('name', $row['category'])
        ->where('is_active', true)->whereNull('parent_id')
        ->lockForUpdate()->sole();
    requireMatch($category->name === $row['category'], 'Category spelling changed.');

    $media = ['featured_kind' => 'image', 'featured_media_id' => (int) $asset->id];
    BuilderFeaturedMedia::check($media, OWNER_ID);

    if ($row['type'] === 'job') {
        requireMatch((BambodiaTaxonomy::jobChoices()[$category->slug] ?? null) === $row['category'],
            'Job category is unavailable.');
        $matches = MarketplaceJob::query()->where('title', $row['title'])->lockForUpdate()->get();
        requireMatch($matches->count() <= 1, 'Job title is ambiguous.');
        $record = $matches->first();
        $changes = ['category' => $category->slug, ...$media];
        if ($record === null) {
            return [$row, null, [
                'title' => $row['title'], 'description' => $row['description'],
                'client_id' => OWNER_ID, 'currency' => 'USD', 'status' => 'draft',
                ...$changes,
            ]];
        }
        requireMatch((int) $record->client_id === OWNER_ID && $record->category === $category->slug,
            'Existing Job has a different owner or category.');
    } else {
        $record = MarketplaceListing::query()->whereKey($row['listing_id'])->lockForUpdate()->first();
        requireMatch($record !== null && (int) $record->user_id === OWNER_ID
            && $record->type === $row['type'] && (string) $record->public_id === $row['listing_public_id'],
            'Listing identity or owner changed.');
        requireMatch($record->category_id === null || (int) $record->category_id === (int) $category->id,
            'Listing already has a different category.');
        $changes = ['category_id' => $category->id, ...$media];
        if ($row['type'] === 'product') {
            requireMatch($record->cover_media_id === null || (int) $record->cover_media_id === (int) $asset->id,
                'Product already has a different cover.');
            $changes['cover_media_id'] = (int) $asset->id;
        }
    }

    requireMatch($record->title === $row['title'] && $record->status === 'draft',
        'Listing title or draft status changed.');
    requireMatch($record->featured_media_id === null || (int) $record->featured_media_id === (int) $asset->id,
        'Listing already has different featured media.');
    requireMatch(in_array($record->featured_kind, [null, '', 'none', 'image'], true),
        'Listing already has a different featured media type.');
    return [$row, $record, $changes];
}

function attachBatch(array $rows, User $owner, string $mode): void
{
    $name = $rows[0]['type'] === 'job' ? 'JOB' : 'MARKETPLACE';
    $result = DB::transaction(function () use ($rows, $owner, $mode): array {
        $plans = [];
        foreach ($rows as $row) {
            try {
                $plans[] = inspectRow($row);
            } catch (Throwable $error) {
                throw new RuntimeException($row['title'].' – '.$error::class
                    .($error::class === RuntimeException::class ? ': '.$error->getMessage() : ''));
            }
        }

        $results = [];
        foreach ($plans as [$row, $record, $changes]) {
            $audit = $row['type'] === 'job'
                ? 'marketplace.job.featured.updated'
                : 'marketplace.listing.featured.updated';
            if ($record === null) {
                requireMatch($mode !== '--verify', $row['title'].' is missing its Job draft.');
                if ($mode === '--apply') {
                    $record = MarketplaceJob::query()->create($changes);
                    AuditEvent::record($audit, $owner);
                }
            } else {
                $record->fill($changes);
                if ($mode === '--verify') {
                    requireMatch(! $record->isDirty(), $row['title'].' is missing its image or category.');
                }
                if ($mode === '--apply' && $record->isDirty()) {
                    $record->save();
                    AuditEvent::record($audit, $owner);
                }
            }

            if ($mode === '--apply') {
                $record->refresh();
                requireMatch($record->status === 'draft'
                    && $record->featured_kind === 'image'
                    && (int) $record->featured_media_id === $row['media_id']
                    && ($row['type'] === 'job'
                        ? $record->category === $changes['category']
                        : (int) $record->category_id === (int) $changes['category_id'])
                    && ($row['type'] !== 'product' || (int) $record->cover_media_id === $row['media_id']),
                    $row['title'].' failed database verification.');
            }
            $results[] = [$row['type'], $record?->public_id ?? 'new', $row['media_public_id']];
        }
        return $results;
    }, 3);

    $label = $mode === '--check' ? 'MATCHED' : 'VERIFIED';
    echo "BAMBODIA_{$name}_COVERS_{$label}=".count($result).'/'.count($rows).PHP_EOL;
    if ($mode !== '--check') {
        foreach ($result as [$type, $listing, $media]) {
            echo "$type listing=$listing media=$media".PHP_EOL;
        }
    }
}

$mode = $argv[1] ?? null;
try {
    requireMatch(PHP_SAPI === 'cli' && count($argv) === 2
        && in_array($mode, ['--check', '--apply', '--verify'], true),
        'Use --check, --apply, or --verify.');
    $www = posix_getpwnam('www');
    requireMatch(is_array($www) && posix_geteuid() === (int) $www['uid'], 'Run as www.');
    requireMatch(realpath(APPLICATION) === APPLICATION && is_file(APPLICATION.'/artisan'),
        'Bambodia installation not found.');
    chdir(APPLICATION);
    require APPLICATION.'/vendor/autoload.php';
    $app = require APPLICATION.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    requireMatch(rtrim((string) config('app.url'), '/') === 'https://bambodia.com',
        'Unexpected application URL.');
    $owner = User::query()->findOrFail(OWNER_ID);
    requireMatch($owner->role === 'super_admin' && $owner->account_status === 'active',
        'Expected active owner not found.');
    $rows = json_decode(MANIFEST, true, 512, JSON_THROW_ON_ERROR);
    requireMatch(count($rows) === 50 && count(array_unique(array_column($rows, 'media_id'))) === 50,
        'Invalid media manifest.');
    $listings = array_values(array_filter($rows, fn ($row) => $row['type'] !== 'job'));
    $jobs = array_values(array_filter($rows, fn ($row) => $row['type'] === 'job'));
    requireMatch(count($listings) === 40 && count($jobs) === 10, 'Unexpected listing counts.');
} catch (Throwable $error) {
    fwrite(STDERR, 'BAMBODIA_COVERS_STOPPED='.$error::class
        .($error::class === RuntimeException::class ? ': '.$error->getMessage() : '').PHP_EOL);
    exit(1);
}

$failures = 0;
foreach ([$listings, $jobs] as $batch) {
    try {
        attachBatch($batch, $owner, $mode);
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, 'BAMBODIA_COVERS_BATCH_STOPPED='.$batch[0]['type'].' '.$error::class
            .($error::class === RuntimeException::class ? ': '.$error->getMessage() : '').PHP_EOL);
    }
}
if ($failures === 0 && $mode !== '--check') {
    echo 'BAMBODIA_LISTING_COVERS=50/50'.PHP_EOL;
}
exit($failures === 0 ? 0 : 1);
