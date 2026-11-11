<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanionController;
use App\Http\Controllers\HyaController;
use App\Http\Controllers\ManagementController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\VisitController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('guest')->group(function () {
    Route::get('/masuk', [AuthController::class, 'create'])->name('login');
    Route::post('/masuk', [AuthController::class, 'store'])->middleware('throttle:6,1');
});

// Family Companion: the link itself is the credential, so these stay public but throttled.
Route::middleware('throttle:30,1')->group(function () {
    Route::get('/pantauan/{token}', [CompanionController::class, 'show']);
    Route::delete('/pantauan/{token}', [CompanionController::class, 'stop']);
});

Route::middleware('auth')->group(function () {
    Route::post('/keluar', [AuthController::class, 'destroy']);

    // Staff and managers land on their own dashboard instead of the patient home.
    Route::get('/', fn (Request $request) => $request->user()->role->value === 'patient'
        ? Inertia::render('Home')
        : redirect($request->user()->role->home()));

    Route::middleware('role:patient')->group(function () {
        Route::get('/poliklinik', [PatientController::class, 'clinics']);
        Route::get('/dokter', [PatientController::class, 'doctors']);
        Route::get('/notifikasi', [PatientController::class, 'notifications']);

        // These screens render from the shared "visit" prop.
        Route::inertia('/kunjungan', 'Visit');
        Route::inertia('/check-in', 'CheckIn');
        Route::inertia('/pendamping', 'CompanionAccess');
        Route::inertia('/akun', 'Account');
        Route::inertia('/hya', 'Hya');
        Route::inertia('/bantuan', 'Help');
        Route::inertia('/bantuan/check-in', 'CheckInGuide');
        Route::inertia('/bantuan/antrean', 'QueueGuide');
        Route::inertia('/petunjuk-ruangan', 'Directions');

        Route::post('/kunjungan', [VisitController::class, 'store']);
        Route::delete('/kunjungan', [VisitController::class, 'destroy']);
        Route::post('/kunjungan/simulasi', [VisitController::class, 'simulate']);
        Route::post('/pendamping', [CompanionController::class, 'store']);
        Route::delete('/pendamping', [CompanionController::class, 'destroy']);
        Route::post('/hya', HyaController::class)->middleware('throttle:30,1');
    });

    Route::middleware('role:staff')->prefix('petugas')->group(function () {
        Route::get('/', [StaffController::class, 'index']);
        Route::post('/check-in', [StaffController::class, 'checkIn']);
        Route::post('/dokter/{doctor}/panggil', [StaffController::class, 'callNext']);
        Route::post('/antrean/{queue}/panggil-ulang', [StaffController::class, 'recall']);
        Route::post('/antrean/{queue}/tidak-hadir', [StaffController::class, 'noShow']);
        Route::post('/antrean/{queue}/selesai-periksa', [StaffController::class, 'examined']);
        Route::post('/antrean/{queue}/selesai-laboratorium', [StaffController::class, 'laboratoryDone']);
        Route::post('/farmasi/{order}/lanjut', [StaffController::class, 'advancePharmacy']);
    });

    Route::middleware('role:manager')->prefix('manajemen')->group(function () {
        Route::get('/', [ManagementController::class, 'index']);
        Route::get('/ekspor', [ManagementController::class, 'export']);
    });
});
