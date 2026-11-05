<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\LicenseController;
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
use App\Http\Controllers\Dashboard\SupportCenterController;
use App\Http\Controllers\Dashboard\FeatureManagerController;
use App\Http\Controllers\Dashboard\ContentManagerController;
use App\Http\Controllers\Dashboard\PolicyManagerController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');
Route::post('/locale', [LocaleController::class, 'update'])->name('locale.update');
Route::get('/blog', [ContentController::class, 'blog'])->name('blog');
Route::get('/licenses', [LicenseController::class, 'show'])->name('license.show');
Route::get('/media/{publicId}/preview', [MediaLibraryController::class, 'preview'])
    ->where('publicId', '[0-9]{9}')->name('media.preview');
Route::get('/policy-center', [PolicyCenterController::class, 'index'])->name('policy-center');
Route::get('/policies', [PolicyCenterController::class, 'show'])->name('policy.show');
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
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::middleware('role:super_admin')->group(function (): void {
        Route::get('/developer', [DeveloperController::class, 'index'])->name('developer.index');
        Route::get('/developer/docs', [DeveloperController::class, 'documentation'])->name('developer.docs');
        Route::post('/developer/tokens', [DeveloperController::class, 'store'])->name('developer.store')->middleware('throttle:5,1');
        Route::delete('/developer/tokens/{token}', [DeveloperController::class, 'revoke'])->name('developer.revoke')->middleware('throttle:10,1');
        Route::get('/security-dashboard', [SecurityDashboardController::class, 'index'])->name('security.index');
    });
    Route::get('/contact', fn () => redirect()->route('support'))->name('contact');
    Route::get('/support-center', [SupportController::class, 'index'])->name('support');
    Route::post('/support-center', [SupportController::class, 'store'])->name('support.store')->middleware('throttle:5,1');
    Route::get('/support-center/{ticket}', [SupportController::class, 'show'])->whereNumber('ticket')->name('support.show');
    Route::post('/support-center/{ticket}/reply', [SupportController::class, 'reply'])->whereNumber('ticket')->name('support.reply')->middleware('throttle:10,1');
    Route::get('/account', [AccountController::class, 'index'])->name('account');
    Route::patch('/account', [AccountController::class, 'update'])->name('account.update');
    Route::get('/studio', [AccountController::class, 'studio'])->name('studio');
    Route::get('/creator', fn () => redirect(
        auth()->user()->isCreator()
            ? '/studio?='.auth()->user()->public_id
            : (in_array(auth()->user()->role, ['customer', 'subscriber'], true)
                ? route('creator.apply')
                : '/account?='.auth()->user()->public_id)
    ))->name('creator');
    Route::get('/creator/apply', [CreatorApplicationController::class, 'show'])->name('creator.apply');
    Route::post('/creator/apply', [CreatorApplicationController::class, 'submit'])->name('creator.apply.store')->middleware('throttle:3,1');
    Route::middleware('role:freelancer,super_admin')->prefix('studio')->group(function (): void {
        Route::get('/listings', [CreatorListingController::class, 'index'])->name('studio.listings');
        Route::post('/listings', [CreatorListingController::class, 'store'])->name('studio.listings.store')->middleware('throttle:10,1');
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
            Route::get('/product-manager', [ProductManagerController::class, 'index'])->name('dashboard.products.index');
            Route::patch('/product-manager/{product}', [ProductManagerController::class, 'decide'])->name('dashboard.products.decide');
            Route::get('/license-manager', [LicenseManagerController::class, 'index'])->name('dashboard.licenses.index');
            Route::post('/license-manager', [LicenseManagerController::class, 'store'])->name('dashboard.licenses.store');
            Route::patch('/license-manager/{license}', [LicenseManagerController::class, 'update'])->name('dashboard.licenses.update');
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
            Route::get('/policy-center', [PolicyManagerController::class, 'index'])->name('dashboard.policies');
            Route::post('/policy-center', [PolicyManagerController::class, 'store'])->name('dashboard.policies.store');
            Route::patch('/policy-center/{policy}', [PolicyManagerController::class, 'update'])->name('dashboard.policies.update');
            Route::get('/creator-applications', [CreatorReviewController::class, 'index'])->name('dashboard.creator-applications');
            Route::patch('/creator-applications/{application}', [CreatorReviewController::class, 'decide'])->name('dashboard.creator-applications.decide');
            Route::get('/review-queue', [ReviewQueueController::class, 'index'])->name('dashboard.reviews');
            Route::patch('/review-queue/{listing}', [ReviewQueueController::class, 'decide'])->name('dashboard.reviews.update');
        });
        Route::middleware('role:super_admin')->group(function (): void {
            Route::get('/feature-manager', [FeatureManagerController::class, 'index'])->name('dashboard.features');
            Route::patch('/feature-manager/{feature}', [FeatureManagerController::class, 'update'])->name('dashboard.features.update');
            Route::get('/user-manager', [UserManagerController::class, 'index'])->name('dashboard.users');
            Route::patch('/user-manager/{user}', [UserManagerController::class, 'update'])->name('dashboard.users.update');
        });
    });
});

Route::get('/{content}', [ContentController::class, 'show'])->where('content', '[0-9]{9}_[a-z0-9-]+')->name('content.show');
