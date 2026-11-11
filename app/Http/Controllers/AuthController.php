<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AuthController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Login', [
            // Shown only in demo mode, where the seeded accounts are meant to be shared.
            'demoAccounts' => config('hytrack.demo_controls')
                ? 'Akun demo — pasien: siti · petugas: dina · manajemen: manajer · kata sandi: '.config('hytrack.demo_password')
                : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:255'],
        ], [
            'username.required' => 'Nama pengguna wajib diisi.',
            'password.required' => 'Kata sandi wajib diisi.',
        ]);

        // Deactivated accounts keep their history but can no longer sign in.
        if (! Auth::attempt([...$credentials, 'is_active' => true])) {
            throw ValidationException::withMessages(['username' => 'Nama pengguna atau kata sandi salah.']);
        }

        $request->session()->regenerate();

        // The intended URL was stored while signed out and may belong to another role.
        $role = $request->user()->role;
        $intended = $request->session()->pull('url.intended');

        return redirect($intended && $role->owns(parse_url($intended, PHP_URL_PATH) ?? '/') ? $intended : $role->home());
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/masuk');
    }
}
