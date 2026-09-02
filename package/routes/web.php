<?php

use Illuminate\Support\Facades\Route;
use SnipeIt\FloatingLicenses\Http\Controllers\FloatingLicenseController;

Route::group(['prefix' => 'floating-licenses', 'as' => 'floating-licenses.'], function () {
    Route::get('/', [FloatingLicenseController::class, 'index'])->name('index');
    Route::get('/create', [FloatingLicenseController::class, 'create'])->name('create');
    Route::post('/licenses/{license}/enable', [FloatingLicenseController::class, 'store'])->name('store');
    Route::get('/{config}', [FloatingLicenseController::class, 'show'])->name('show');
    Route::get('/{config}/edit', [FloatingLicenseController::class, 'edit'])->name('edit');
    Route::put('/{config}', [FloatingLicenseController::class, 'update'])->name('update');
    Route::delete('/{config}', [FloatingLicenseController::class, 'destroy'])->name('destroy');
    Route::post('/{config}/allocate', [FloatingLicenseController::class, 'allocate'])->name('allocate');
    Route::post('/allocations/{allocation}/release', [FloatingLicenseController::class, 'release'])->name('allocations.release');
    Route::post('/license/{license}/bulk-add', [FloatingLicenseController::class, 'bulkAddUsers'])->name('license.bulk-add');
    Route::post('/license/{license}/bulk-remove', [FloatingLicenseController::class, 'bulkRemoveUsers'])->name('license.bulk-remove');
    Route::get('/license/{license}/bulk-add', [FloatingLicenseController::class, 'bulkAddForm'])->name('license.bulk-add.form');
    Route::get('/license/{license}/bulk-remove', [FloatingLicenseController::class, 'bulkRemoveForm'])->name('license.bulk-remove.form');
});
