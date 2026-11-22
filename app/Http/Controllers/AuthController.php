<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\User;
use App\Services\CompanionInvitations;
use App\Services\SignupVerification;
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
        // "Masuk sebagai" on the sign-in screen: where a patient account lands, not a separate role.
        $as = $request->validate(['as' => ['nullable', 'in:patient,companion']])['as'] ?? 'patient';
        $credentials['username'] = $this->accountName($credentials['username']);

        // Deactivated accounts keep their history but can no longer sign in.
        if (! Auth::attempt([...$credentials, 'is_active' => true])) {
            throw ValidationException::withMessages(['username' => 'Nomor ponsel atau kata sandi salah.']);
        }

        return $this->signedIn($request, $as === 'companion');
    }

    public function createAccount(): Response
    {
        return Inertia::render('Register');
    }

    /** Step 1/3: check the form, then send the code. The account is created after step 2. */
    public function storeAccount(Request $request, SignupVerification $signup): RedirectResponse
    {
        $request->session()->put('signup', $signup->start($signup->validated($request)));

        return redirect('/daftar/verifikasi');
    }

    /** Step 2/3, Figma "Kode OTP" (32:286). */
    public function verification(Request $request, SignupVerification $signup): Response|RedirectResponse
    {
        $token = $request->session()->get('signup');
        if (! $signup->exists($token)) {
            return redirect('/daftar');
        }

        return Inertia::render('RegisterOtp', $signup->screen($token));
    }

    public function verify(Request $request, SignupVerification $signup): RedirectResponse
    {
        $code = $request->validate(['code' => ['required', 'digits:6']], [
            'code.required' => 'Masukkan 6 digit kode OTP.',
            'code.digits' => 'Masukkan 6 digit kode OTP.',
        ])['code'];
        $user = $signup->createAccount($signup->verify((string) $request->session()->get('signup'), $code));
        $request->session()->forget('signup');

        Auth::login($user);
        // Where the app would have gone (e.g. back to an invitation link), behind the success screen.
        $request->session()->put('signup_next', $this->signedIn($request)->getTargetUrl());

        return redirect('/daftar/selesai');
    }

    public function resend(Request $request, SignupVerification $signup): RedirectResponse
    {
        $signup->resend((string) $request->session()->get('signup'));

        return back()->with('status', 'Kode baru telah dikirim.');
    }

    /** Step 3/3, Figma "Verifikasi berhasil" (32:330). */
    public function verified(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('RegisterVerified', [
            'phone' => SignupVerification::mask($user->patient?->phone_number ?? $user->username),
            'next' => $request->session()->get('signup_next', '/'),
        ]);
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

    private function signedIn(Request $request, bool $asCompanion = false): RedirectResponse
    {
        $request->session()->regenerate();
        $user = $request->user();
        $role = $user->role;
        // Remembered for the session, so reopening the app ("/") returns to the companion view.
        $request->session()->put('companion', $asCompanion && $role === UserRole::Patient);

        // The intended URL was stored while signed out and may belong to another role.
        $intended = $request->session()->pull('url.intended');
        if ($intended && $role->owns(parse_url($intended, PHP_URL_PATH) ?? '/')) {
            return redirect($intended);
        }

        // A companion is a patient account following someone else's visit: open that visit,
        // or the list when there are several (or none yet). Staff and managers ignore the choice.
        if ($request->session()->get('companion')) {
            return redirect(app(CompanionInvitations::class)->home($user) ?? '/pendampingan');
        }

        return redirect($role->home());
    }
}
