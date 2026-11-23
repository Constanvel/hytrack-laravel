<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\User;
use App\Services\SignupVerification;
use Illuminate\Database\UniqueConstraintViolationException;
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
                ? 'Akun demo — pasien: 0812-5550-0842 · pendamping: 0812-5550-0843 · petugas: dina · manajemen: manajer · kata sandi: '.config('hytrack.demo_password')
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
    public function storeAccount(Request $request, SignupVerification $verification): RedirectResponse
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

        $this->ensurePhoneIsFree($data['phone'], 'phone');

        // With WhatsApp OTP on, the account is created only after the code is confirmed.
        if ($verification->required()) {
            $verification->start($request->session(), $data);

            return redirect('/daftar/verifikasi');
        }

        return $this->createPatient($request, $data['name'], $data['phone'], $data['password']);
    }

    public function verifyAccount(Request $request, SignupVerification $verification): Response|RedirectResponse
    {
        $pending = $verification->pending($request->session());
        if (! isset($pending['sent_at'])) {
            return redirect('/daftar');
        }

        return Inertia::render('VerifyPhone', [
            'phone' => Patient::formatPhone($pending['phone']),
            'minutesValid' => SignupVerification::MINUTES_VALID,
            'resendIn' => $verification->secondsUntilResend($request->session()),
        ]);
    }

    public function confirmAccount(Request $request, SignupVerification $verification): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']], [
            'code.required' => 'Masukkan kode 6 angka dari WhatsApp.',
            'code.digits' => 'Kode terdiri dari 6 angka.',
        ]);
        $pending = $verification->verify($request->session(), $data['code']);
        // Someone may have registered the same number while the code was on its way.
        $this->ensurePhoneIsFree($pending['phone'], 'code');

        return $this->createPatient($request, $pending['name'], $pending['phone'], $pending['password_hash']);
    }

    public function resendCode(Request $request, SignupVerification $verification): RedirectResponse
    {
        $verification->send($request->session());

        return redirect('/daftar/verifikasi');
    }

    /** "Ganti nomor": drop the pending sign-up and go back to the form. */
    public function cancelVerification(Request $request, SignupVerification $verification): RedirectResponse
    {
        $verification->cancel($request->session());

        return redirect('/daftar');
    }

    private function ensurePhoneIsFree(string $phone, string $field): void
    {
        if (Patient::whereNotNull('user_id')->wherePhone($phone)->exists() || User::where('username', $phone)->exists()) {
            throw ValidationException::withMessages([$field => 'Nomor ponsel sudah terdaftar. Silakan masuk.']);
        }
    }

    /** @param  string  $password  plain text or an existing hash; the model's "hashed" cast keeps a hash as is */
    private function createPatient(Request $request, string $name, string $phone, string $password): RedirectResponse
    {
        try {
            // users + patients in one transaction: never an account without a profile.
            $user = DB::transaction(function () use ($name, $phone, $password) {
                $user = User::create([
                    'username' => $phone,
                    'password_hash' => $password,
                    'role' => UserRole::Patient,
                ]);
                Patient::create([
                    'user_id' => $user->user_id,
                    'full_name' => trim($name),
                    'phone_number' => Patient::formatPhone($phone),
                ]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Two sign-ups with the same number at the same moment: users.username is unique.
            throw ValidationException::withMessages(['phone' => 'Nomor ponsel sudah terdaftar. Silakan masuk.']);
        }

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
