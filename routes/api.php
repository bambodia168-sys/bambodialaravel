<?php

use App\Http\Controllers\Api\AccountApiController;
use App\Http\Controllers\Api\ListingApiController;
use App\Http\Controllers\Api\ProductLicenseApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:30,1')->group(function (): void {
    // External installed scripts send the bearer license secret in POST body over HTTPS.
    // Neither activation nor validation creates purchase records or paid entitlements.
    Route::post('/licenses/activate', [ProductLicenseApiController::class,'activate'])
        ->middleware('throttle:12,1')->name('api.v1.licenses.activate');
    Route::post('/licenses/validate', [ProductLicenseApiController::class,'validateInstallation'])
        ->middleware('throttle:30,1')->name('api.v1.licenses.validate');
    Route::get('/account', [AccountApiController::class, 'show'])
        ->middleware('api.token:account.read')->name('api.v1.account');
    Route::get('/listings', [ListingApiController::class, 'index'])
        ->middleware('api.token:listings.read')->name('api.v1.listings');
});
