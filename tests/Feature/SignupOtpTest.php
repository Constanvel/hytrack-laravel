<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SignupOtpTest extends TestCase
{
    use RefreshDatabase;

    private const SIGN_UP = [
        'name' => 'Budi Santoso',
        'phone' => '0813-2222-3333',
        'password' => 'rahasia-123',
        'password_confirmation' => 'rahasia-123',
        'terms' => '1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.wangsaf.key' => 'kunci-uji']);
        Http::preventStrayRequests();
    }

    private function fakeWangsaf(int $status = 200): void
    {
        Http::fake(['https://api.wangsaf.com/*' => Http::response(['status' => $status, 'message' => 'x'])]);
    }

    /** The code inside the last WhatsApp message Wangsaf was asked to send. */
    private function lastCode(): string
    {
        $sent = Http::recorded()->last()[0];
        preg_match('/\b(\d{6})\b/', $sent['message'], $match);

        return $match[1];
    }

    public function test_sign_up_waits_for_the_whatsapp_code_before_creating_the_account(): void
    {
        $this->fakeWangsaf();

        $this->post('/daftar', self::SIGN_UP)->assertRedirect('/daftar/verifikasi');
        $this->assertSame(0, User::count());
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.wangsaf.com/v1/chat/send-text'
            && $request->hasHeader('Api-Key', 'kunci-uji')
            && $request['target'] === '6281322223333@s.whatsapp.net'
            && str_contains($request['message'], 'Jangan berikan kode ini'));
        // The password never travels to WhatsApp.
        Http::assertNotSent(fn (Request $request) => str_contains($request->body(), 'rahasia-123'));

        $this->get('/daftar/verifikasi')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('VerifyPhone')->where('phone', '+62 813-2222-3333')->where('resendIn', 60));

        $this->post('/daftar/verifikasi', ['code' => '12345'])->assertSessionHasErrors(['code' => 'Kode terdiri dari 6 angka.']);
        $wrong = $this->lastCode() === '000000' ? '111111' : '000000';
        $this->post('/daftar/verifikasi', ['code' => $wrong])->assertSessionHasErrors(['code' => 'Kode salah. Sisa 4 kali percobaan.']);
        $this->assertGuest();

        $this->post('/daftar/verifikasi', ['code' => $this->lastCode()])->assertRedirect('/');
        $user = User::where('username', '6281322223333')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Budi Santoso', $user->patient->full_name);

        // The password chosen on the form still signs in after the code step.
        $this->post('/keluar');
        $this->post('/masuk', ['username' => '0813-2222-3333', 'password' => 'rahasia-123'])->assertRedirect('/');
    }

    public function test_a_failed_whatsapp_send_keeps_the_patient_on_the_form(): void
    {
        // Wangsaf reports a wrong key as HTTP 200 with status 401 in the body.
        $this->fakeWangsaf(401);

        $this->post('/daftar', self::SIGN_UP)
            ->assertSessionHasErrors(['phone' => 'Kode verifikasi gagal dikirim ke WhatsApp +62 813-2222-3333. Pastikan nomor ini aktif di WhatsApp, lalu coba lagi.']);
        $this->get('/daftar/verifikasi')->assertRedirect('/daftar');
        $this->assertSame(0, User::count());
    }

    public function test_codes_expire_lock_after_five_wrong_tries_and_can_be_resent(): void
    {
        $this->fakeWangsaf();
        $this->post('/daftar', self::SIGN_UP);
        $first = $this->lastCode();

        $this->post('/daftar/verifikasi/kirim-ulang')->assertSessionHasErrors('code');
        $this->travel(61)->seconds();
        $this->post('/daftar/verifikasi/kirim-ulang')->assertRedirect('/daftar/verifikasi');
        $second = $this->lastCode();
        Http::assertSentCount(2);

        if ($first !== $second) {
            $this->post('/daftar/verifikasi', ['code' => $first])->assertSessionHasErrors('code');
        }
        $wrong = $second === '000000' ? '111111' : '000000';
        for ($try = 0; $try < 5; $try++) {
            $this->post('/daftar/verifikasi', ['code' => $wrong]);
        }
        $this->post('/daftar/verifikasi', ['code' => $second])
            ->assertSessionHasErrors(['code' => 'Terlalu banyak kode salah. Tekan "Kirim ulang kode".']);

        $this->travel(61)->seconds();
        $this->post('/daftar/verifikasi/kirim-ulang');
        $this->travel(6)->minutes();
        $this->post('/daftar/verifikasi', ['code' => $this->lastCode()])
            ->assertSessionHasErrors(['code' => 'Kode sudah kedaluwarsa. Tekan "Kirim ulang kode".']);

        // Three codes per number per hour.
        $this->post('/daftar/verifikasi/kirim-ulang')->assertSessionHasErrors('code');
        Http::assertSentCount(3);
        $this->assertGuest();
    }

    public function test_change_number_drops_the_pending_sign_up(): void
    {
        $this->fakeWangsaf();
        $this->post('/daftar', self::SIGN_UP);

        $this->post('/daftar/verifikasi/batal')->assertRedirect('/daftar');
        $this->get('/daftar/verifikasi')->assertRedirect('/daftar');
    }

    public function test_a_number_registered_meanwhile_is_not_registered_twice(): void
    {
        $this->fakeWangsaf();
        $this->post('/daftar', self::SIGN_UP);
        Patient::factory()->create(['phone_number' => '+62 813-2222-3333']);

        $this->post('/daftar/verifikasi', ['code' => $this->lastCode()])
            ->assertSessionHasErrors(['code' => 'Nomor ponsel sudah terdaftar. Silakan masuk.']);
        $this->assertSame(0, User::where('username', '6281322223333')->count());
    }
}
