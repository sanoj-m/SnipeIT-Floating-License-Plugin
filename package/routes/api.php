<?php

use Illuminate\Support\Facades\Route;
use SnipeIt\FloatingLicenses\Http\Controllers\Api\FloatingLicenseApiController;

Route::group(['prefix' => 'v1', 'middleware' => ['api', 'api-throttle:api']], function () {
    Route::post('floating-licenses/{license}/allocate', [FloatingLicenseApiController::class, 'allocate'])
        ->name('api.floating-licenses.allocate');
    Route::get('floating-licenses/{license}/availability', [FloatingLicenseApiController::class, 'availability'])
        ->name('api.floating-licenses.availability');
    Route::post('floating-license-allocations/{allocation}/heartbeat', [FloatingLicenseApiController::class, 'heartbeat'])
        ->name('api.floating-license-allocations.heartbeat');
    Route::post('floating-license-allocations/{allocation}/release', [FloatingLicenseApiController::class, 'release'])
        ->name('api.floating-license-allocations.release');
});
