<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Dashboard\DashboardController;
use App\Http\Controllers\Dashboard\UserManagerController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

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
    Route::get('/studio', [AccountController::class, 'studio'])->name('studio');
    Route::get('/creator', fn () => redirect('/studio?='.auth()->user()->public_id))->name('creator');

    Route::prefix('dashboard')->middleware('role:super_admin,admin,staff')->group(function (): void {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::middleware('role:super_admin')->group(function (): void {
            Route::get('/user-manager', [UserManagerController::class, 'index'])->name('dashboard.users');
            Route::patch('/user-manager/{user}', [UserManagerController::class, 'update'])->name('dashboard.users.update');
        });
    });
});
