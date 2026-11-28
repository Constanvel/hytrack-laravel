<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\CompanionController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\ClinicalController;
use App\Http\Controllers\ManagementController;
use App\Http\Controllers\PolyclinicController;
use App\Http\Controllers\StaffController as Staff;
use Illuminate\Support\Facades\Route;

/*
 * JSON API for the Android app (prefix /api). Same database, services and rules as the
 * web screens; authenticated with "Authorization: Bearer <token>" from POST /api/masuk.
 */
Route::post('/masuk', [AccountController::class, 'login'])->middleware('throttle:6,1');
Route::post('/daftar', [AccountController::class, 'register'])->middleware('throttle:6,1');
Route::post('/daftar/verifikasi', [AccountController::class, 'verify'])->middleware('throttle:10,1');
Route::post('/daftar/kirim-ulang', [AccountController::class, 'resendCode'])->middleware('throttle:10,1');

Route::middleware(['auth:api', 'throttle:120,1'])->group(function () {
    Route::post('/keluar', [AccountController::class, 'logout']);
    Route::get('/akun', [AccountController::class, 'show']);

    Route::middleware('role:patient')->group(function () {
        Route::get('/data-pasien', [AccountController::class, 'patients']);
        Route::post('/data-pasien', [AccountController::class, 'storePatient']);
        Route::put('/data-pasien/{patient}', [AccountController::class, 'updatePatient'])->whereNumber('patient');
        Route::delete('/data-pasien/{patient}', [AccountController::class, 'destroyPatient'])->whereNumber('patient');
        Route::get('/riwayat', [AccountController::class, 'history']);

        Route::get('/pendamping', [CompanionController::class, 'index']);
        Route::post('/pendamping', [CompanionController::class, 'store']);
        Route::delete('/pendamping/{share}', [CompanionController::class, 'destroy'])->whereNumber('share');

        Route::get('/pantauan/{token}', [CompanionController::class, 'preview'])->middleware('throttle:30,1');
        Route::post('/pantauan/{token}', [CompanionController::class, 'accept'])->middleware('throttle:30,1');
        Route::get('/pendampingan', [CompanionController::class, 'following']);
        Route::get('/pendampingan/{share}', [CompanionController::class, 'show'])->whereNumber('share');
        Route::delete('/pendampingan/{share}', [CompanionController::class, 'stop'])->whereNumber('share');
    });

    // Staff and management area of the Android app: the web dashboards' controllers, as JSON.
    Route::middleware('role:staff')->prefix('petugas')->group(function () {
        Route::get('/', [Staff::class, 'index']);
        Route::post('/scan', [StaffController::class, 'scan'])->middleware('throttle:60,1');
        Route::post('/check-in', [StaffController::class, 'checkIn']);
        Route::get('/pasien', [Staff::class, 'findPatient'])->middleware('throttle:60,1');
        Route::get('/jadwal', [Staff::class, 'schedule']);
        Route::post('/booking', [Staff::class, 'book']);
        Route::post('/antrean/{queue}/batal', [Staff::class, 'cancel']);
        Route::post('/dokter/{doctor}/panggil', [Staff::class, 'callNext']);
        Route::post('/antrean/{queue}/panggil', [Staff::class, 'call']);
        Route::post('/antrean/{queue}/panggil-ulang', [Staff::class, 'recall']);
        Route::post('/antrean/{queue}/tidak-hadir', [Staff::class, 'noShow']);
        Route::post('/pemeriksaan/{queue}', [ClinicalController::class, 'save']);
        Route::post('/pemeriksaan/{queue}/konfirmasi', [ClinicalController::class, 'confirm']);
        Route::post('/pemeriksaan/{queue}/penunjang', [ClinicalController::class, 'refer']);
        Route::post('/penunjang/{support}', [ClinicalController::class, 'updateSupport']);
        Route::post('/penunjang/{support}/selesai', [ClinicalController::class, 'completeSupport']);
        Route::get('/penunjang/{support}/hasil', [ClinicalController::class, 'result']);
        Route::post('/farmasi/{order}/lanjut', [Staff::class, 'advancePharmacy']);
        Route::post('/farmasi/{order}/cek', [Staff::class, 'savePharmacyChecks']);
        Route::post('/farmasi/{order}/panggil', [Staff::class, 'callPickup']);
        Route::post('/farmasi/{order}/kembalikan', [Staff::class, 'returnPrescription']);
        Route::post('/poliklinik', [PolyclinicController::class, 'store']);
        Route::put('/poliklinik/{polyclinic}', [PolyclinicController::class, 'update'])->whereNumber('polyclinic');
        Route::delete('/poliklinik/{polyclinic}', [PolyclinicController::class, 'destroy'])->whereNumber('polyclinic');
    });

    Route::middleware('role:manager')->prefix('manajemen')->group(function () {
        Route::get('/', [ManagementController::class, 'index']);
        Route::get('/ekspor', [ManagementController::class, 'export']);
    });
});
