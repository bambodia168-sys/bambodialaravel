<?php

use App\Http\Controllers\Api\AccountApiController;
use App\Http\Controllers\Api\ListingApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:30,1')->group(function (): void {
    Route::get('/account', [AccountApiController::class, 'show'])
        ->middleware('api.token:account.read')->name('api.v1.account');
    Route::get('/listings', [ListingApiController::class, 'index'])
        ->middleware('api.token:listings.read')->name('api.v1.listings');
});
