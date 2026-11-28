<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

abstract class Controller
{
    /**
     * The outcome of a staff action: the web gets a flash message and goes back, the Android
     * app (routes/api.php, same controllers) gets the message as JSON.
     */
    protected function done(string $message): RedirectResponse|JsonResponse
    {
        if (request()->is('api/*')) {
            return response()->json(['message' => $message]);
        }
        Inertia::flash('status', $message);

        return back();
    }

    /**
     * Every recorded staff action names a staff member; an account without a staff profile
     * may not act. $room limits the action to one room (Staff::DOCTOR, SUPPORT, PHARMACY).
     */
    protected function staff(Request $request, ?string $room = null): Staff
    {
        $staff = $request->user()->staff ?? abort(403, 'Akun ini tidak terdaftar sebagai petugas.');
        abort_if($room && $staff->room() !== $room, 403, match ($room) {
            Staff::DOCTOR => 'Hanya dokter pemeriksa yang dapat mengonfirmasi pemeriksaan dan resep.',
            Staff::SUPPORT => 'Tindakan ini hanya untuk petugas penunjang.',
            Staff::PHARMACY => 'Tindakan ini hanya untuk petugas farmasi.',
            default => 'Akses ditolak.',
        });

        return $staff;
    }
}
