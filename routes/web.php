<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\BuilderController;
use App\Http\Controllers\SavedJobController;
use App\Http\Controllers\SavedListingController;
use App\Http\Controllers\Creator\CreatorProfileController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PrivacyRequestController;
use App\Http\Controllers\Dashboard\PrivacyRequestManagerController;
use App\Http\Controllers\ContractArtifactController;
use App\Http\Controllers\PolicyAcknowledgementController;
use App\Http\Controllers\Dashboard\ComplianceRegisterController;
use App\Http\Controllers\FreelanceContractController;
use App\Http\Controllers\ContractMilestoneController;
use App\Http\Controllers\ContractCommunicationController;
use App\Http\Controllers\Dashboard\ContractOversightController;
use App\Http\Controllers\Dashboard\SiteSettingsController;
use App\Http\Controllers\Dashboard\CategoryManagerController;
use App\Http\Controllers\Dashboard\MarketplaceManagerController;
use App\Http\Controllers\AccountNotificationController;
use App\Http\Controllers\Dashboard\NotificationAnalyticsController;
use App\Http\Controllers\ServiceInquiryController;
use App\Http\Controllers\Dashboard\ServiceManagerController;
use App\Http\Controllers\ResumeController;
use App\Http\Controllers\JobBoardController;
use App\Http\Controllers\Dashboard\JobManagerController;
use App\Http\Controllers\Account\ProductDownloadController;
use App\Http\Controllers\Creator\ProductVaultController;
use App\Http\Controllers\Dashboard\ProductVaultReviewController;
use App\Http\Controllers\Dashboard\ProductEntitlementController;
use App\Http\Controllers\LicenseController;
use App\Http\Controllers\Account\ProductLicenseController;
use App\Http\Controllers\Creator\ProductLicensesController;
use App\Http\Controllers\Dashboard\IssuedLicenseController;
use App\Http\Controllers\Dashboard\ProductSupportController;
use App\Http\Controllers\MediaLibraryController;
use App\Http\Controllers\Creator\ProductController as CreatorProductController;
use App\Http\Controllers\Dashboard\LicenseManagerController;
use App\Http\Controllers\Dashboard\ProductManagerController;
use App\Http\Controllers\DeveloperController;
use App\Http\Controllers\SecurityDashboardController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Creator\ListingController as CreatorListingController;
use App\Http\Controllers\Creator\CreatorApplicationController;
use App\Http\Controllers\Dashboard\CreatorReviewController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\ReviewQueueController;
use App\Http\Controllers\Dashboard\UserManagerController;
use App\Http\Controllers\Marketplace\ListingController;
use App\Http\Controllers\PolicyCenterController;
use App\Http\Controllers\ContentController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\KnowledgeBaseController;
use App\Http\Controllers\Dashboard\SupportCenterController;
use App\Http\Controllers\Dashboard\KnowledgeBaseController as KnowledgeBaseManagerController;
use App\Http\Controllers\Dashboard\FeatureManagerController;
use App\Http\Controllers\Dashboard\ContentManagerController;
use App\Http\Controllers\Dashboard\PolicyManagerController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class,'index'])->name('home');
Route::get('/creators', [CreatorProfileController::class,'directory'])->name('creators.index');
Route::get('/creators/{creator}', [CreatorProfileController::class,'show'])
    ->where('creator','[0-9]{8}')->name('creators.show');
Route::post('/locale', [LocaleController::class, 'update'])->name('locale.update');
Route::get('/blog', [ContentController::class, 'blog'])->name('blog');
Route::get('/jobs', [JobBoardController::class, 'index'])->name('jobs');
Route::get('/jobs/{job}', [JobBoardController::class, 'show'])->name('jobs.show')
    ->where('job','[0-9]{9}-[a-z0-9-]+');
Route::get('/licenses', [LicenseController::class, 'show'])->name('license.show');
Route::get('/media/{publicId}/preview', [MediaLibraryController::class, 'preview'])
    ->where('publicId', '[0-9]{9}')->name('media.preview');
Route::get('/support-center', [KnowledgeBaseController::class, 'index'])->name('support');
Route::get('/support-center/articles/{reference}-{slug}', [KnowledgeBaseController::class, 'show'])
    ->where(['reference'=>'[0-9]{8}','slug'=>'[a-z0-9-]+'])->name('support.article.show');
Route::get('/contact', fn () => redirect()->route('support'))->name('contact');
Route::get('/policy-center', [PolicyCenterController::class, 'index'])->name('policy-center');
Route::get('/policies', [PolicyCenterController::class, 'show'])->name('policy.show');
Route::get('/policies/{reference}-{slug}', [PolicyCenterController::class, 'showByReference'])
    ->where(['reference' => '[0-9]{8}', 'slug' => '[a-z0-9-]+'])->name('policy.reference.show');
Route::get('/search', [ListingController::class, 'search'])->name('marketplace.search');
foreach (['product', 'project', 'freelance', 'service'] as $type) {
    Route::get('/'.$type, fn (\Illuminate\Http\Request $request) => app(ListingController::class)->show($request, $type))->name('marketplace.'.$type);
}

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'authenticate'])->name('login.store')->middleware('throttle:8,1');
    Route::get('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/register', [AuthController::class, 'store'])->name('register.store')->middleware('throttle:5,1');
    Route::get('/forgot-password', [AuthController::class, 'forgotPassword'])->name('password.request');
    Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->name('password.email')->middleware('throttle:3,1');
    Route::get('/reset-password/{token}', [AuthController::class, 'resetPassword'])->name('password.reset');
    Route::post('/reset-password', [AuthController::class, 'updatePassword'])->name('password.update')->middleware('throttle:5,1');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/builder', [BuilderController::class, 'show'])->name('builder');
    Route::post('/builder/content/preview', [BuilderController::class, 'previewContent'])
        ->name('builder.content.preview')->middleware('throttle:30,1');
    Route::post('/builder/media', [MediaLibraryController::class, 'store'])
        ->name('builder.media.store')->middleware('throttle:30,1');
    Route::post('/builder/products/{product}/files', [ProductVaultController::class, 'storeFromBuilder'])
        ->name('builder.product.files.store')->middleware('role:freelancer,super_admin')->middleware('throttle:5,1');
    Route::patch('/builder', [BuilderController::class, 'updateJob'])
        ->name('builder.job.update')->middleware('throttle:10,1');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::middleware('role:super_admin')->group(function (): void {
        Route::get('/developer', [DeveloperController::class, 'index'])->name('developer.index');
        Route::get('/developer/docs', [DeveloperController::class, 'documentation'])->name('developer.docs');
        Route::post('/developer/tokens', [DeveloperController::class, 'store'])->name('developer.store')->middleware('throttle:5,1');
        Route::delete('/developer/tokens/{token}', [DeveloperController::class, 'revoke'])->name('developer.revoke')->middleware('throttle:10,1');
        Route::get('/security-dashboard', [SecurityDashboardController::class, 'index'])->name('security.index');
    });
    Route::get('/support-center/tickets', [SupportController::class, 'index'])->name('support.tickets');
    Route::post('/support-center', [SupportController::class, 'store'])->name('support.store')->middleware('throttle:5,1');
    Route::get('/support-center/{ticket}', [SupportController::class, 'show'])->whereNumber('ticket')->name('support.show');
    Route::post('/support-center/{ticket}/reply', [SupportController::class, 'reply'])->whereNumber('ticket')->name('support.reply')->middleware('throttle:10,1');
    Route::get('/privacy-requests', [PrivacyRequestController::class,'index'])->name('privacy.index');
    Route::post('/privacy-requests', [PrivacyRequestController::class,'store'])
        ->name('privacy.store')->middleware('throttle:3,1');
    Route::get('/privacy-requests/{privacyRequest}', [PrivacyRequestController::class,'show'])
        ->name('privacy.show')->where('privacyRequest','[0-9]{9}');
    Route::patch('/privacy-requests/{privacyRequest}/close', [PrivacyRequestController::class,'close'])
        ->name('privacy.close')->where('privacyRequest','[0-9]{9}');
    Route::get('/account/policy-receipts', [PolicyAcknowledgementController::class,'index'])->name('account.policy-receipts');
    Route::post('/policies/acknowledgements/{publication}', [PolicyAcknowledgementController::class,'acknowledge'])
        ->name('policy.acknowledge')->middleware('throttle:10,1');
    Route::get('/account/saved-jobs', [SavedJobController::class,'index'])->name('account.saved-jobs');
    Route::post('/account/saved-jobs/{job}', [SavedJobController::class,'store'])
        ->name('saved-jobs.store')->middleware('throttle:20,1');
    Route::delete('/account/saved-jobs/{job}', [SavedJobController::class,'destroy'])
        ->name('saved-jobs.destroy')->middleware('throttle:20,1');
    Route::get('/account/saved-listings', [SavedListingController::class,'index'])->name('account.saved-listings');
    Route::post('/account/saved-listings/{listing}', [SavedListingController::class,'store'])
        ->name('saved-listings.store')->middleware('throttle:20,1');
    Route::delete('/account/saved-listings/{listing}', [SavedListingController::class,'destroy'])
        ->name('saved-listings.destroy')->middleware('throttle:20,1');
    Route::get('/account/notifications', [AccountNotificationController::class, 'index'])->name('account.notifications');
    Route::patch('/account/notifications/read-all', [AccountNotificationController::class, 'readAll'])->name('account.notifications.read-all');
    Route::patch('/account/notifications/{notification}', [AccountNotificationController::class, 'read'])->name('account.notifications.read');
    Route::get('/account/inquiries', [ServiceInquiryController::class, 'index'])->name('account.inquiries');
    Route::get('/inquiries/{inquiry}', [ServiceInquiryController::class, 'show'])->name('inquiries.show');
    Route::post('/inquiries/for/{listing}', [ServiceInquiryController::class, 'store'])
        ->name('inquiries.store')->middleware('throttle:5,1');
    Route::post('/inquiries/{inquiry}/reply', [ServiceInquiryController::class, 'reply'])
        ->name('inquiries.reply')->middleware('throttle:20,1');
    Route::patch('/inquiries/{inquiry}/close', [ServiceInquiryController::class, 'close'])->name('inquiries.close');
    Route::get('/resume-builder', [ResumeController::class, 'edit'])->name('resume.edit');
    Route::patch('/resume-builder', [ResumeController::class, 'update'])->name('resume.update');
    Route::get('/contracts', [FreelanceContractController::class,'index'])->name('contracts.index');
    Route::post('/contracts', [FreelanceContractController::class,'store'])->name('contracts.store')->middleware('throttle:5,1');
    Route::get('/contracts/{contract}', [FreelanceContractController::class,'show'])->name('contracts.show');
    Route::post('/contracts/{contract}/milestones', [FreelanceContractController::class,'addMilestone'])->name('contracts.milestones.store');
    Route::delete('/contract-milestones/{milestone}', [FreelanceContractController::class,'removeMilestone'])->name('contracts.milestones.destroy');
    Route::post('/contracts/{contract}/offer', [FreelanceContractController::class,'offer'])->name('contracts.offer');
    Route::post('/contracts/{contract}/respond', [FreelanceContractController::class,'respond'])->name('contracts.respond');
    Route::post('/contracts/{contract}/cancel', [FreelanceContractController::class,'cancel'])->name('contracts.cancel');
    Route::post('/contracts/{contract}/acknowledge-completion', [FreelanceContractController::class,'acknowledgeCompletion'])->name('contracts.acknowledge');
    Route::post('/contracts/{contract}/complete', [FreelanceContractController::class,'complete'])->name('contracts.complete');
    Route::get('/contract-submissions/{submission}/artifact', [ContractArtifactController::class,'download'])
        ->name('contracts.artifacts.download')->middleware('throttle:10,1');
    Route::post('/contract-milestones/{milestone}/deliver', [ContractMilestoneController::class,'deliver'])
        ->name('contracts.milestones.deliver')->middleware('throttle:8,1');
    Route::post('/contract-milestones/{milestone}/review', [ContractMilestoneController::class,'review'])
        ->name('contracts.milestones.review');
    Route::post('/contracts/{contract}/messages', [ContractCommunicationController::class,'message'])
        ->name('contracts.messages.store')->middleware('throttle:20,1');
    Route::post('/contracts/{contract}/disputes', [ContractCommunicationController::class,'dispute'])
        ->name('contracts.disputes.store')->middleware('throttle:2,1');
    Route::get('/my-jobs', [JobBoardController::class, 'mine'])->name('jobs.mine');
    Route::post('/my-jobs', [JobBoardController::class, 'store'])->name('jobs.store')->middleware('throttle:5,1');
    Route::patch('/my-jobs/{job}/close', [JobBoardController::class, 'close'])->name('jobs.close');
    Route::get('/my-jobs/{job}/proposals', [JobBoardController::class, 'proposals'])->name('jobs.proposals');
    Route::patch('/my-jobs/proposals/{proposal}', [JobBoardController::class, 'decide'])->name('jobs.proposals.decide');
    Route::post('/jobs/{job}/apply', [JobBoardController::class, 'apply'])
        ->name('jobs.apply')->middleware('throttle:5,1');
    Route::get('/my-proposals', [JobBoardController::class, 'applied'])->name('jobs.applied');
    Route::get('/account/licenses', [ProductLicenseController::class, 'index'])->name('account.licenses.index');
    Route::post('/account/licenses/{license}/reveal', [ProductLicenseController::class, 'reveal'])
        ->name('account.licenses.reveal')->middleware('throttle:5,1');
    Route::patch('/account/licenses/{license}/domain', [ProductLicenseController::class, 'changeDomain'])
        ->name('account.licenses.domain')->middleware('throttle:4,1');
    Route::post('/account/licenses/{license}/support-renewals', [ProductLicenseController::class,'requestSupport'])
        ->name('account.licenses.support.request')->middleware('throttle:3,1');
    Route::get('/account/downloads', [ProductDownloadController::class, 'index'])->name('account.downloads');
    Route::get('/account/downloads/{entitlement}/file', [ProductDownloadController::class, 'download'])
        ->name('account.downloads.file')->middleware('throttle:10,1');
    Route::get('/account', [AccountController::class, 'index'])->name('account');
    Route::patch('/account', [AccountController::class, 'update'])->name('account.update');
    Route::get('/studio', [AccountController::class, 'studio'])->name('studio');
    Route::get('/creator', function (\Illuminate\Http\Request $request) {
        $user = $request->user();

        return $user->isCreator()
            ? app(AccountController::class)->studio($request)
            : redirect(in_array($user->role, ['customer', 'subscriber'], true)
                ? route('creator.apply')
                : '/account?='.$user->public_id);
    })->name('creator');
    Route::get('/creator/apply', [CreatorApplicationController::class, 'show'])->name('creator.apply');
    Route::post('/creator/apply', [CreatorApplicationController::class, 'submit'])->name('creator.apply.store')->middleware('throttle:3,1');
    Route::middleware('role:freelancer,super_admin')->prefix('studio')->group(function (): void {
        Route::get('/profile', [CreatorProfileController::class,'edit'])->name('studio.profile.edit');
        Route::patch('/profile', [CreatorProfileController::class,'update'])
            ->name('studio.profile.update')->middleware('throttle:10,1');
        Route::get('/inquiries', [ServiceInquiryController::class, 'index'])->name('studio.inquiries');
        Route::get('/listings', [CreatorListingController::class, 'index'])->name('studio.listings');
        Route::post('/listings', [CreatorListingController::class, 'store'])->name('studio.listings.store')->middleware('throttle:10,1');
        Route::patch('/listings/{listing}', [CreatorListingController::class, 'update'])->name('studio.listings.update');
        Route::get('/products/{product}/releases', [ProductVaultController::class, 'index'])->name('studio.vault.index');
        Route::patch('/products/{product}/releases/{version}/rights', [ProductVaultController::class,'evidence'])
            ->name('studio.vault.rights');
        Route::post('/products/{product}/releases', [ProductVaultController::class, 'store'])
            ->name('studio.vault.store')->middleware('throttle:5,1');
        Route::get('/licenses', [ProductLicensesController::class,'index'])->name('studio.licenses.index');
        Route::post('/licenses/{license}/request-review', [ProductLicensesController::class,'requestReview'])
            ->name('studio.licenses.request-review')->middleware('throttle:3,1');
        Route::get('/products', [CreatorProductController::class, 'index'])->name('studio.products.index');
        Route::post('/products', [CreatorProductController::class, 'store'])->name('studio.products.store')->middleware('throttle:10,1');
        Route::patch('/products/{product}', [CreatorProductController::class, 'update'])->name('studio.products.update')->middleware('throttle:10,1');
        Route::get('/media-library', [MediaLibraryController::class, 'index'])->name('studio.media.index');
        Route::post('/media-library', [MediaLibraryController::class, 'store'])->name('studio.media.store')->middleware('throttle:10,1');
        Route::patch('/media-library/{asset}', [MediaLibraryController::class, 'update'])->name('studio.media.update');
        Route::delete('/media-library/{asset}', [MediaLibraryController::class, 'destroy'])->name('studio.media.destroy');
    });

    Route::prefix('dashboard')->middleware('role:super_admin,admin,staff')->group(function (): void {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/support-center', [SupportCenterController::class, 'index'])->name('dashboard.support');
        Route::get('/support-center/{ticket}', [SupportCenterController::class, 'show'])->whereNumber('ticket')->name('dashboard.support.show');
        Route::post('/support-center/{ticket}/reply', [SupportCenterController::class, 'reply'])->whereNumber('ticket')->name('dashboard.support.reply');
        Route::patch('/support-center/{ticket}/status', [SupportCenterController::class, 'status'])->whereNumber('ticket')->name('dashboard.support.status');
        Route::middleware('role:super_admin,admin')->group(function (): void {
            Route::get('/file-vault', [ProductVaultReviewController::class, 'index'])->name('dashboard.vault.index');
            Route::patch('/file-vault/{version}', [ProductVaultReviewController::class, 'decide'])->name('dashboard.vault.decide');
            Route::post('/file-vault/{version}/scan', [ProductVaultReviewController::class,'scan'])
                ->name('dashboard.vault.scan')->middleware('throttle:2,1');
            Route::post('/file-vault/{version}/rights', [ProductVaultReviewController::class,'rights'])
                ->name('dashboard.vault.rights')->middleware('throttle:10,1');
            Route::post('/file-vault/{version}/inspect', [ProductVaultReviewController::class, 'inspect'])
                ->name('dashboard.vault.inspect')->middleware('throttle:10,1');
            Route::get('/notification-analytics', [NotificationAnalyticsController::class, 'index'])->name('dashboard.notifications.index');
            Route::get('/service-inquiries', [ServiceManagerController::class, 'index'])->name('dashboard.inquiries.index');
            Route::get('/appearance', [SiteSettingsController::class, 'appearance'])->name('dashboard.appearance');
            Route::patch('/appearance', [SiteSettingsController::class, 'updateAppearance'])->name('dashboard.appearance.update');
            Route::get('/system-settings', [SiteSettingsController::class, 'system'])->name('dashboard.system-settings');
            Route::patch('/system-settings', [SiteSettingsController::class, 'updateSystem'])->name('dashboard.system-settings.update');
            Route::get('/category-manager', [CategoryManagerController::class, 'index'])->name('dashboard.categories.index');
            Route::post('/category-manager', [CategoryManagerController::class, 'store'])->name('dashboard.categories.store');
            Route::patch('/category-manager/{category}', [CategoryManagerController::class, 'update'])->name('dashboard.categories.update');
            Route::get('/{type}-manager', [MarketplaceManagerController::class, 'index'])
                ->where('type','project|freelance|service')->name('dashboard.marketplace.index');
            Route::get('/contract-manager', [ContractOversightController::class,'index'])->name('dashboard.contracts.index');
            Route::get('/contract-disputes', [ContractOversightController::class,'disputes'])->name('dashboard.contracts.disputes');
            Route::get('/contract-disputes/{dispute}', [ContractOversightController::class,'inspect'])->name('dashboard.contracts.inspect');
            Route::patch('/contract-disputes/{dispute}', [ContractOversightController::class,'decide'])->name('dashboard.contracts.decide');
            Route::get('/job-manager', [JobManagerController::class, 'index'])->name('dashboard.jobs.index');
            Route::patch('/job-manager/{job}', [JobManagerController::class, 'decide'])->name('dashboard.jobs.decide');
            Route::get('/product-manager', [ProductManagerController::class, 'index'])->name('dashboard.products.index');
            Route::patch('/product-manager/{product}', [ProductManagerController::class, 'decide'])->name('dashboard.products.decide');
            Route::get('/license-manager', [LicenseManagerController::class, 'index'])->name('dashboard.licenses.index');
            Route::post('/license-manager', [LicenseManagerController::class, 'store'])->name('dashboard.licenses.store');
            Route::patch('/license-manager/{license}', [LicenseManagerController::class, 'update'])->name('dashboard.licenses.update');
            Route::post('/license-manager/issue', [IssuedLicenseController::class,'issue'])
                ->name('dashboard.licenses.issue')->middleware('throttle:5,1');
            Route::patch('/license-manager/issued/{license}/status', [IssuedLicenseController::class,'status'])
                ->name('dashboard.licenses.issued.status')->middleware('throttle:10,1');
            Route::patch('/license-manager/issued/{license}/upgrade', [IssuedLicenseController::class,'upgrade'])
                ->name('dashboard.licenses.issued.upgrade')->middleware('throttle:5,1');
            Route::patch('/license-manager/issued/{license}/domain', [IssuedLicenseController::class,'changeDomain'])
                ->name('dashboard.licenses.issued.domain')->middleware('throttle:10,1');
            Route::post('/license-manager/issued/{license}/rotate', [IssuedLicenseController::class,'rotate'])
                ->name('dashboard.licenses.issued.rotate')->middleware('throttle:5,1');
            Route::post('/license-manager/support-renewals/{renewal}/review', [ProductSupportController::class,'review'])
                ->name('dashboard.licenses.support.review')->middleware('throttle:6,1');
            Route::get('/media-library', [MediaLibraryController::class, 'index'])->name('dashboard.media.index');
            Route::post('/media-library', [MediaLibraryController::class, 'store'])->name('dashboard.media.store')->middleware('throttle:10,1');
            Route::patch('/media-library/{asset}', [MediaLibraryController::class, 'update'])->name('dashboard.media.update');
            Route::delete('/media-library/{asset}', [MediaLibraryController::class, 'destroy'])->name('dashboard.media.destroy');
            Route::patch('/media-library/{asset}/review', [MediaLibraryController::class, 'review'])->name('dashboard.media.review');
            Route::get('/page-manager', [ContentManagerController::class, 'pages'])->name('dashboard.pages');
            Route::post('/page-manager', [ContentManagerController::class, 'storePage'])->name('dashboard.pages.store');
            Route::patch('/page-manager/{entry}', [ContentManagerController::class, 'updatePage'])->name('dashboard.pages.update');
            Route::get('/post-manager', [ContentManagerController::class, 'posts'])->name('dashboard.posts');
            Route::post('/post-manager', [ContentManagerController::class, 'storePost'])->name('dashboard.posts.store');
            Route::patch('/post-manager/{entry}', [ContentManagerController::class, 'updatePost'])->name('dashboard.posts.update');
            Route::get('/knowledge-base', [KnowledgeBaseManagerController::class, 'index'])->name('dashboard.knowledge.index');
            Route::get('/knowledge-base/create', [KnowledgeBaseManagerController::class, 'create'])->name('dashboard.knowledge.create');
            Route::get('/knowledge-base/{article}/edit', [KnowledgeBaseManagerController::class, 'edit'])->name('dashboard.knowledge.edit');
            Route::get('/knowledge-base/{article}/preview', [KnowledgeBaseManagerController::class, 'preview'])->name('dashboard.knowledge.preview');
            Route::post('/knowledge-base', [KnowledgeBaseManagerController::class, 'store'])->name('dashboard.knowledge.store')->middleware('throttle:10,1');
            Route::patch('/knowledge-base/{article}', [KnowledgeBaseManagerController::class, 'update'])->name('dashboard.knowledge.update')->middleware('throttle:10,1');
            Route::post('/knowledge-base/starter-drafts', [KnowledgeBaseManagerController::class, 'seed'])->name('dashboard.knowledge.seed')->middleware('throttle:3,1');
            Route::get('/policy-center', [PolicyManagerController::class, 'index'])->name('dashboard.policies');
            Route::get('/policy-center/create', [PolicyManagerController::class, 'create'])->name('dashboard.policies.create');
            Route::get('/policy-center/{policy}/edit', [PolicyManagerController::class, 'edit'])->name('dashboard.policies.edit');
            Route::get('/policy-center/{policy}/preview', [PolicyManagerController::class, 'preview'])->name('dashboard.policies.preview');
            Route::post('/policy-center', [PolicyManagerController::class, 'store'])->name('dashboard.policies.store');
            Route::post('/policy-center/legal-drafts', [PolicyManagerController::class, 'seedLegalDrafts'])->name('dashboard.policies.legal-drafts')->middleware('throttle:3,1');
            Route::post('/policy-center/supplemental-drafts', [PolicyManagerController::class, 'seedSupplementalDrafts'])->name('dashboard.policies.supplemental-drafts')->middleware('throttle:3,1');
            Route::patch('/policy-center/{policy}', [PolicyManagerController::class, 'update'])->name('dashboard.policies.update');
            Route::get('/creator-applications', [CreatorReviewController::class, 'index'])->name('dashboard.creator-applications');
            Route::patch('/creator-applications/{application}', [CreatorReviewController::class, 'decide'])->name('dashboard.creator-applications.decide');
            Route::get('/review-queue', [ReviewQueueController::class, 'index'])->name('dashboard.reviews');
            Route::patch('/review-queue/{listing}', [ReviewQueueController::class, 'decide'])->name('dashboard.reviews.update');
        });
        Route::middleware('role:super_admin')->group(function (): void {
            Route::get('/privacy-requests', [PrivacyRequestManagerController::class,'index'])->name('dashboard.privacy.index');
            Route::get('/privacy-requests/{privacyRequest}', [PrivacyRequestManagerController::class,'inspect'])
                ->name('dashboard.privacy.inspect')->where('privacyRequest','[0-9]{9}');
            Route::patch('/privacy-requests/{privacyRequest}', [PrivacyRequestManagerController::class,'update'])
                ->name('dashboard.privacy.update')->where('privacyRequest','[0-9]{9}');
            Route::get('/compliance-register', [ComplianceRegisterController::class,'index'])->name('dashboard.compliance.index');
            Route::get('/entitlements', [ProductEntitlementController::class, 'index'])->name('dashboard.entitlements.index');
            Route::post('/entitlements', [ProductEntitlementController::class, 'grant'])
                ->name('dashboard.entitlements.grant')->middleware('throttle:6,1');
            Route::patch('/entitlements/{entitlement}/revoke', [ProductEntitlementController::class, 'revoke'])
                ->name('dashboard.entitlements.revoke');
            Route::get('/feature-manager', [FeatureManagerController::class, 'index'])->name('dashboard.features');
            Route::patch('/feature-manager/{feature}', [FeatureManagerController::class, 'update'])->name('dashboard.features.update');
            Route::get('/user-manager', [UserManagerController::class, 'index'])->name('dashboard.users');
            Route::patch('/user-manager/{user}', [UserManagerController::class, 'update'])->name('dashboard.users.update');
        });
    });
});

Route::get('/{content}', [ContentController::class, 'show'])->where('content', '[0-9]{9}_[a-z0-9-]+')->name('content.show');


// Localized counterparts of the existing web routes keep legacy URLs valid.
// Use literal prefixes (rather than a {locale} route parameter) so existing
// controller method arguments, model bindings and signed download IDs remain
// unchanged. Route names are unique and preserve the original authorization,
// throttling and parameter constraints.
$unprefixedWebRoutes = Route::getRoutes()->getRoutes();
foreach (\App\Http\Middleware\SetLocale::SUPPORTED as $languageCode) {
    foreach ($unprefixedWebRoutes as $originalRoute) {
        $name = $originalRoute->getName();
        if ($name === null || str_starts_with($name, 'localized.')) {
            continue;
        }
        $action = $originalRoute->getAction('uses');
        if ($action === null) {
            continue;
        }
        $methods = array_values(array_diff($originalRoute->methods(), ['HEAD']));
        $path = trim($originalRoute->uri(), '/');
        $localizedRoute = Route::match(
            $methods,
            '/'.$languageCode.($path === '' ? '' : '/'.$path),
            $action
        )->middleware($originalRoute->gatherMiddleware())
            ->name('localized.'.$languageCode.'.'.$name);
        foreach ($originalRoute->wheres as $parameter => $constraint) {
            $localizedRoute->where($parameter, $constraint);
        }
    }
}
