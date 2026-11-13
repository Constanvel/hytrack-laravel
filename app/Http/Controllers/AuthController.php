<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
                ? 'Akun demo — pasien: 0812-5550-0842 · petugas: dina · manajemen: manajer · kata sandi: '.config('hytrack.demo_password')
                : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:255'],
        ], [
            'username.required' => 'Nomor ponsel wajib diisi.',
            'password.required' => 'Kata sandi wajib diisi.',
        ]);
        $credentials['username'] = $this->accountName($credentials['username']);

        // Deactivated accounts keep their history but can no longer sign in.
        if (! Auth::attempt([...$credentials, 'is_active' => true])) {
            throw ValidationException::withMessages(['username' => 'Nomor ponsel atau kata sandi salah.']);
        }

        return $this->signedIn($request);
    }

    public function createAccount(): Response
    {
        return Inertia::render('Register');
    }

    /** Self sign-up creates a patient account; staff and manager accounts stay hospital-issued. */
    public function storeAccount(Request $request): RedirectResponse
    {
        $request->merge(['phone' => Patient::normalizePhone((string) $request->input('phone'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'regex:/^628\d{7,11}$/'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
            'terms' => ['accepted'],
        ], [
            'name.required' => 'Nama lengkap wajib diisi.',
            'name.max' => 'Nama lengkap maksimal 100 karakter.',
            'phone.required' => 'Nomor ponsel wajib diisi.',
            'phone.regex' => 'Masukkan nomor ponsel yang valid, mis. 0812-3456-7890.',
            'password.required' => 'Kata sandi wajib diisi.',
            'password.min' => 'Kata sandi minimal 8 karakter.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak sama.',
            'terms.accepted' => 'Setujui Syarat & Ketentuan dan Kebijakan Privasi untuk melanjutkan.',
        ]);

        if (Patient::wherePhone($data['phone'])->exists() || User::where('username', $data['phone'])->exists()) {
            throw ValidationException::withMessages(['phone' => 'Nomor ponsel sudah terdaftar. Silakan masuk.']);
        }

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'username' => $data['phone'],
                'password_hash' => $data['password'],
                'role' => UserRole::Patient,
            ]);
            Patient::create([
                'user_id' => $user->user_id,
                'full_name' => trim($data['name']),
                'phone_number' => Patient::formatPhone($data['phone']),
            ]);

            return $user;
        });

        Auth::login($user);

        return $this->signedIn($request);
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/masuk');
    }

    /** Patients sign in with their phone number; staff and managers keep their username. */
    private function accountName(string $login): string
    {
        if (preg_match('/[a-z]/i', $login)) {
            return $login;
        }

        return User::whereHas('patient', fn ($patient) => $patient->wherePhone(Patient::normalizePhone($login)))
            ->value('username') ?? $login;
    }

    private function signedIn(Request $request): RedirectResponse
    {
        $request->session()->regenerate();

        // The intended URL was stored while signed out and may belong to another role.
        $role = $request->user()->role;
        $intended = $request->session()->pull('url.intended');

        return redirect($intended && $role->owns(parse_url($intended, PHP_URL_PATH) ?? '/') ? $intended : $role->home());
    }
}
