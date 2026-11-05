<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Creator\ListingController as CreatorListingController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\ReviewQueueController;
use App\Http\Controllers\Dashboard\UserManagerController;
use App\Http\Controllers\Marketplace\ListingController;
use App\Http\Controllers\PolicyCenterController;
use App\Http\Controllers\Dashboard\PolicyManagerController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');
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
    Route::get('/account', [AccountController::class, 'index'])->name('account');
    Route::patch('/account', [AccountController::class, 'update'])->name('account.update');
    Route::get('/studio', [AccountController::class, 'studio'])->name('studio');
    Route::get('/creator', fn () => redirect('/studio?='.auth()->user()->public_id))->name('creator');
    Route::middleware('role:freelancer,super_admin')->prefix('studio')->group(function (): void {
        Route::get('/listings', [CreatorListingController::class, 'index'])->name('studio.listings');
        Route::post('/listings', [CreatorListingController::class, 'store'])->name('studio.listings.store')->middleware('throttle:10,1');
    });

    Route::prefix('dashboard')->middleware('role:super_admin,admin,staff')->group(function (): void {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::middleware('role:super_admin,admin')->group(function (): void {
            Route::get('/policy-center', [PolicyManagerController::class, 'index'])->name('dashboard.policies');
            Route::post('/policy-center', [PolicyManagerController::class, 'store'])->name('dashboard.policies.store');
            Route::patch('/policy-center/{policy}', [PolicyManagerController::class, 'update'])->name('dashboard.policies.update');
            Route::get('/review-queue', [ReviewQueueController::class, 'index'])->name('dashboard.reviews');
            Route::patch('/review-queue/{listing}', [ReviewQueueController::class, 'decide'])->name('dashboard.reviews.update');
        });
        Route::middleware('role:super_admin')->group(function (): void {
            Route::get('/user-manager', [UserManagerController::class, 'index'])->name('dashboard.users');
            Route::patch('/user-manager/{user}', [UserManagerController::class, 'update'])->name('dashboard.users.update');
        });
    });
});
