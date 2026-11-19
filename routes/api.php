<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\CompanionController;
use App\Http\Controllers\Api\StaffController;
use Illuminate\Support\Facades\Route;

/*
 * JSON API for the Android app (prefix /api). Same database, services and rules as the
 * web screens; authenticated with "Authorization: Bearer <token>" from POST /api/masuk.
 */
Route::post('/masuk', [AccountController::class, 'login'])->middleware('throttle:6,1');

Route::middleware(['auth:api', 'throttle:120,1'])->group(function () {
    Route::post('/keluar', [AccountController::class, 'logout']);
    Route::get('/akun', [AccountController::class, 'show']);

    Route::middleware('role:patient')->group(function () {
        Route::get('/data-pasien', [AccountController::class, 'patients']);
        Route::post('/data-pasien', [AccountController::class, 'storePatient']);
        Route::put('/data-pasien/{patient}', [AccountController::class, 'updatePatient'])->whereNumber('patient');

        Route::get('/pendamping', [CompanionController::class, 'index']);
        Route::post('/pendamping', [CompanionController::class, 'store']);
        Route::delete('/pendamping/{share}', [CompanionController::class, 'destroy'])->whereNumber('share');

        Route::get('/pantauan/{token}', [CompanionController::class, 'preview'])->middleware('throttle:30,1');
        Route::post('/pantauan/{token}', [CompanionController::class, 'accept'])->middleware('throttle:30,1');
        Route::get('/pendampingan', [CompanionController::class, 'following']);
        Route::get('/pendampingan/{share}', [CompanionController::class, 'show'])->whereNumber('share');
        Route::delete('/pendampingan/{share}', [CompanionController::class, 'stop'])->whereNumber('share');
    });

    Route::middleware('role:staff')->prefix('petugas')->group(function () {
        Route::post('/scan', [StaffController::class, 'scan'])->middleware('throttle:60,1');
        Route::post('/check-in', [StaffController::class, 'checkIn']);
    });
});
