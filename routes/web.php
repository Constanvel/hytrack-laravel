<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanionController;
use App\Http\Controllers\HyaController;
use App\Http\Controllers\ManagementController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientDataController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\VisitController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('guest')->group(function () {
    Route::get('/masuk', [AuthController::class, 'create'])->name('login');
    Route::post('/masuk', [AuthController::class, 'store'])->middleware('throttle:6,1');
    Route::get('/daftar', [AuthController::class, 'createAccount']);
    Route::post('/daftar', [AuthController::class, 'storeAccount'])->middleware('throttle:6,1');
});

// Family Companion invitation link: public so guests can be asked to sign in, but throttled.
Route::get('/pantauan/{token}', [CompanionController::class, 'show'])->middleware('throttle:30,1');

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
        Route::get('/riwayat', [PatientController::class, 'history']);

        // These screens render from the shared "visit" prop.
        Route::inertia('/kunjungan', 'Visit');
        Route::inertia('/check-in', 'CheckIn');
        Route::inertia('/akun', 'Account');
        Route::inertia('/hya', 'Hya');
        Route::inertia('/bantuan', 'Help');
        Route::inertia('/bantuan/check-in', 'CheckInGuide');
        Route::inertia('/bantuan/antrean', 'QueueGuide');
        Route::inertia('/petunjuk-ruangan', 'Directions');

        Route::post('/kunjungan', [VisitController::class, 'store']);
        Route::delete('/kunjungan', [VisitController::class, 'destroy']);
        Route::post('/kunjungan/simulasi', [VisitController::class, 'simulate']);
        Route::get('/data-pasien', [PatientDataController::class, 'index']);
        Route::get('/data-pasien/tambah', [PatientDataController::class, 'create']);
        Route::post('/data-pasien', [PatientDataController::class, 'store']);
        Route::get('/data-pasien/{patient}/ubah', [PatientDataController::class, 'edit'])->whereNumber('patient');
        Route::put('/data-pasien/{patient}', [PatientDataController::class, 'update'])->whereNumber('patient');

        // Family Companion, patient side: invite, list and revoke.
        Route::get('/pendamping', [CompanionController::class, 'access']);
        Route::post('/pendamping', [CompanionController::class, 'store']);
        Route::delete('/pendamping/{share}', [CompanionController::class, 'destroy'])->whereNumber('share');

        // Companion side: any patient account can accept an invitation and follow that visit.
        Route::post('/pantauan/{token}', [CompanionController::class, 'accept'])->middleware('throttle:30,1');
        Route::get('/pendampingan', [CompanionController::class, 'following']);
        Route::get('/pendampingan/{share}', [CompanionController::class, 'monitor'])->whereNumber('share');
        Route::delete('/pendampingan/{share}', [CompanionController::class, 'stop'])->whereNumber('share');
        Route::post('/hya', HyaController::class)->middleware('throttle:30,1');
    });

    Route::middleware('role:staff')->prefix('petugas')->group(function () {
        Route::get('/', [StaffController::class, 'index']);
        Route::post('/scan', [StaffController::class, 'scan'])->middleware('throttle:60,1');
        Route::post('/check-in', [StaffController::class, 'checkIn']);
        // Bookings: create for a walk-in patient (with JSON lookups), cancel with a reason.
        Route::get('/pasien', [StaffController::class, 'findPatient'])->middleware('throttle:60,1');
        Route::get('/jadwal', [StaffController::class, 'schedule']);
        Route::post('/booking', [StaffController::class, 'book']);
        Route::post('/antrean/{queue}/batal', [StaffController::class, 'cancel']);
        Route::post('/dokter/{doctor}/panggil', [StaffController::class, 'callNext']);
        Route::post('/antrean/{queue}/panggil', [StaffController::class, 'call']);
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
