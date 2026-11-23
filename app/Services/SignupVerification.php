<?php

namespace App\Services;

use App\Models\Patient;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * One-time code sent by WhatsApp before a self sign-up creates the account, so a patient
 * account always belongs to a number its owner can read. The pending sign-up (with the
 * password already hashed) and a keyed hash of the code wait in the server-side session.
 */
class SignupVerification
{
    public const MINUTES_VALID = 5;

    public const RESEND_SECONDS = 60;

    private const KEY = 'signup_verification';

    private const MAX_WRONG = 5;

    private const SENDS_PER_HOUR = 3;

    public function __construct(private readonly WhatsApp $whatsapp) {}

    /** Without a Wangsaf key, sign-up keeps working without a code. */
    public function required(): bool
    {
        return $this->whatsapp->enabled();
    }

    /** @param  array{name: string, phone: string, password: string}  $data */
    public function start(Session $session, array $data): void
    {
        $session->put(self::KEY, [
            'name' => trim($data['name']),
            'phone' => $data['phone'],
            'password_hash' => Hash::make($data['password']),
        ]);

        $this->send($session, 'phone');
    }

    /** @return array{name: string, phone: string, password_hash: string, sent_at?: int}|null */
    public function pending(Session $session): ?array
    {
        return $session->get(self::KEY);
    }

    public function secondsUntilResend(Session $session): int
    {
        $sentAt = $this->pending($session)['sent_at'] ?? 0;

        return max(0, $sentAt + self::RESEND_SECONDS - now()->timestamp);
    }

    /** Sends a new code (the previous one stops working). Errors are reported on $field. */
    public function send(Session $session, string $field = 'code'): void
    {
        $pending = $this->pending($session) ?? throw ValidationException::withMessages([$field => 'Isi formulir pendaftaran lebih dulu.']);

        if ($wait = $this->secondsUntilResend($session)) {
            throw ValidationException::withMessages([$field => "Tunggu {$wait} detik sebelum meminta kode baru."]);
        }

        $limit = 'signup-otp:'.$pending['phone'];
        if (RateLimiter::tooManyAttempts($limit, self::SENDS_PER_HOUR)) {
            $minutes = (int) ceil(RateLimiter::availableIn($limit) / 60);
            throw ValidationException::withMessages([$field => "Terlalu banyak permintaan kode untuk nomor ini. Coba lagi dalam {$minutes} menit."]);
        }
        RateLimiter::hit($limit, 3600);

        $code = (string) random_int(100000, 999999);
        $message = "Kode verifikasi HyTrack: {$code}\n\nBerlaku ".self::MINUTES_VALID.' menit. '
            .'Jangan berikan kode ini kepada siapa pun, termasuk petugas rumah sakit.';

        if (! $this->whatsapp->send($pending['phone'], $message)) {
            throw ValidationException::withMessages([$field => 'Kode verifikasi gagal dikirim ke WhatsApp '
                .Patient::formatPhone($pending['phone']).'. Pastikan nomor ini aktif di WhatsApp, lalu coba lagi.']);
        }

        $session->put(self::KEY, [
            ...$pending,
            'code_hash' => $this->hash($code),
            'expires_at' => now()->addMinutes(self::MINUTES_VALID)->timestamp,
            'sent_at' => now()->timestamp,
            'wrong' => 0,
        ]);
    }

    /**
     * The pending sign-up once the code matches; it is then cleared from the session.
     *
     * @return array{name: string, phone: string, password_hash: string}
     */
    public function verify(Session $session, string $code): array
    {
        $pending = $this->pending($session);
        if (! isset($pending['code_hash'])) {
            throw ValidationException::withMessages(['code' => 'Isi formulir pendaftaran lebih dulu.']);
        }
        if (now()->timestamp > $pending['expires_at']) {
            throw ValidationException::withMessages(['code' => 'Kode sudah kedaluwarsa. Tekan "Kirim ulang kode".']);
        }
        if ($pending['wrong'] >= self::MAX_WRONG) {
            throw ValidationException::withMessages(['code' => 'Terlalu banyak kode salah. Tekan "Kirim ulang kode".']);
        }

        if (! hash_equals($pending['code_hash'], $this->hash($code))) {
            $session->put(self::KEY.'.wrong', $pending['wrong'] + 1);
            $left = self::MAX_WRONG - $pending['wrong'] - 1;

            throw ValidationException::withMessages(['code' => $left > 0
                ? "Kode salah. Sisa {$left} kali percobaan."
                : 'Kode salah. Tekan "Kirim ulang kode" untuk mendapat kode baru.']);
        }

        $session->forget(self::KEY);

        return $pending;
    }

    public function cancel(Session $session): void
    {
        $session->forget(self::KEY);
    }

    private function hash(string $code): string
    {
        return hash_hmac('sha256', $code, (string) config('app.key'));
    }
}
