<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sign-up step 2/3 (Figma "Kode OTP"): the account is created only after the phone number is
 * confirmed with a 6-digit code. The pending sign-up lives in the cache under a random token,
 * shared by the web session and the Android API; the password is kept hashed, the code too.
 */
class SignupVerification
{
    public const CODE_TTL = 300;

    public const RESEND_AFTER = 45;

    public const MAX_ATTEMPTS = 5;

    private const SENDS_PER_HOUR = 5;

    public function __construct(private readonly WhatsApp $whatsapp) {}

    /**
     * Step 1/3: the sign-up form. Self sign-up creates a patient account; staff and manager
     * accounts stay hospital-issued.
     *
     * @return array{name: string, phone: string, password: string}
     */
    public function validated(Request $request): array
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
        $this->ensureFree($data['phone']);

        return ['name' => $data['name'], 'phone' => $data['phone'], 'password' => $data['password']];
    }

    /**
     * Step 3/3: users + patients in one transaction, never an account without a profile.
     *
     * @param  array{name: string, phone: string, password_hash: string}  $verified
     */
    public function createAccount(array $verified): User
    {
        // The number may have been taken while the code was on its way.
        $this->ensureFree($verified['phone']);
        try {
            return DB::transaction(function () use ($verified) {
                $user = User::create([
                    'username' => $verified['phone'],
                    'password_hash' => $verified['password_hash'], // already hashed: the cast keeps it
                    'role' => UserRole::Patient,
                ]);
                Patient::create([
                    'user_id' => $user->user_id,
                    'full_name' => $verified['name'],
                    'phone_number' => Patient::formatPhone($verified['phone']),
                ]);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Two sign-ups with the same number at the same moment: users.username is unique.
            throw ValidationException::withMessages(['phone' => 'Nomor ponsel sudah terdaftar. Silakan masuk.']);
        }
    }

    private function ensureFree(string $phone): void
    {
        if (Patient::whereNotNull('user_id')->wherePhone($phone)->exists() || User::where('username', $phone)->exists()) {
            throw ValidationException::withMessages(['phone' => 'Nomor ponsel sudah terdaftar. Silakan masuk.']);
        }
    }

    /** @param  array{name: string, phone: string, password: string}  $data */
    public function start(array $data): string
    {
        $token = Str::random(40);
        $this->send($token, [
            'name' => trim($data['name']),
            'phone' => $data['phone'],
            'password_hash' => Hash::make($data['password']),
        ], 'phone');

        return $token;
    }

    public function resend(string $token): void
    {
        $pending = $this->pending($token);
        if (now()->timestamp < $pending['resend_at']) {
            throw ValidationException::withMessages(['code' => 'Tunggu sebentar sebelum meminta kode baru.']);
        }
        $this->send($token, $pending, 'code');
    }

    /**
     * Checks the code and, when right, ends the pending sign-up.
     *
     * @return array{name: string, phone: string, password_hash: string}
     */
    public function verify(string $token, string $code): array
    {
        $pending = $this->pending($token);
        if ($pending['attempts'] >= self::MAX_ATTEMPTS) {
            throw ValidationException::withMessages(['code' => 'Terlalu banyak percobaan. Minta kode baru.']);
        }
        if (now()->timestamp > $pending['expires_at']) {
            throw ValidationException::withMessages(['code' => 'Kode sudah kedaluwarsa. Minta kode baru.']);
        }
        if (! hash_equals($pending['code_hash'], $this->hash($token, $code))) {
            $pending['attempts']++;
            $this->cache()->put($this->key($token), $pending, now()->addMinutes(30));
            throw ValidationException::withMessages(['code' => 'Kode OTP salah. Periksa kembali '
                .($this->whatsapp->enabled() ? 'pesan WhatsApp' : 'SMS').' Anda.']);
        }
        $this->cache()->forget($this->key($token));

        return ['name' => $pending['name'], 'phone' => $pending['phone'], 'password_hash' => $pending['password_hash']];
    }

    /** What the OTP screen shows: masked number, seconds until resend, and the demo code. */
    public function screen(string $token): array
    {
        $pending = $this->pending($token);

        return [
            'phone' => self::mask($pending['phone']),
            'resendIn' => max(0, $pending['resend_at'] - now()->timestamp),
            'expiresIn' => max(0, $pending['expires_at'] - now()->timestamp),
            'channel' => $this->channel(),
            // No SMS gateway yet: demo mode shows the code on screen so the flow can be tried.
            'demoCode' => config('hytrack.demo_controls') ? $pending['demo_code'] ?? null : null,
        ];
    }

    public function exists(?string $token): bool
    {
        return $token !== null && $this->cache()->has($this->key($token));
    }

    /** "+62 812 •••• 7890", as on the OTP and success screens. */
    public static function mask(string $phone): string
    {
        $digits = Patient::normalizePhone($phone);

        return '+62 '.substr($digits, 2, 3).' •••• '.substr($digits, -4);
    }

    /** Errors land on $field: "phone" on the sign-up form, "code" on the OTP screen. */
    private function send(string $token, array $pending, string $field): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        if ($this->whatsapp->enabled()) {
            $this->deliver($pending['phone'], $code, $field);
        }
        $pending = array_merge($pending, [
            'code_hash' => $this->hash($token, $code),
            'expires_at' => now()->timestamp + self::CODE_TTL,
            'resend_at' => now()->timestamp + self::RESEND_AFTER,
            'attempts' => 0,
            'demo_code' => config('hytrack.demo_controls') ? $code : null,
        ]);
        $this->cache()->put($this->key($token), $pending, now()->addMinutes(30));
        // Without WANGSAF_API_KEY no message is sent: outside demo mode the code only reaches
        // the log. With WhatsApp on, the code never goes to the log.
        if (! $this->whatsapp->enabled() && ! config('hytrack.demo_controls')) {
            Log::info('Kode OTP pendaftaran', ['phone' => self::mask($pending['phone']), 'code' => $code]);
        }
    }

    /** WhatsApp through Wangsaf, at most SENDS_PER_HOUR codes per number so the quota can't be drained. */
    private function deliver(string $phone, string $code, string $field): void
    {
        $limit = 'signup-otp:'.$phone;
        if (RateLimiter::tooManyAttempts($limit, self::SENDS_PER_HOUR)) {
            $minutes = (int) ceil(RateLimiter::availableIn($limit) / 60);
            throw ValidationException::withMessages([$field => "Terlalu banyak permintaan kode untuk nomor ini. Coba lagi dalam {$minutes} menit."]);
        }
        RateLimiter::hit($limit, 3600);

        $message = "Kode OTP HyTrack: {$code}\n\nBerlaku ".(self::CODE_TTL / 60).' menit. '
            .'Jangan berikan kode ini kepada siapa pun, termasuk petugas rumah sakit.';
        if (! $this->whatsapp->send($phone, $message)) {
            throw ValidationException::withMessages([$field => 'Kode OTP gagal dikirim ke WhatsApp '.self::mask($phone)
                .'. Pastikan nomor ini aktif di WhatsApp, lalu coba lagi.']);
        }
    }

    private function channel(): string
    {
        return $this->whatsapp->enabled() ? 'WhatsApp' : 'SMS';
    }

    private function pending(string $token): array
    {
        return $this->cache()->get($this->key($token)) ?? throw ValidationException::withMessages([
            'code' => 'Sesi verifikasi berakhir. Silakan daftar ulang.',
        ]);
    }

    private function hash(string $token, string $code): string
    {
        return hash_hmac('sha256', $code, $token.config('app.key'));
    }

    private function cache(): Repository
    {
        return Cache::store(config('hytrack.signup_store'));
    }

    private function key(string $token): string
    {
        return 'signup:'.hash('sha256', $token);
    }
}
