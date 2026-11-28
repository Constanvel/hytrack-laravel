<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** The sign-up OTP goes out by WhatsApp through Wangsaf once WANGSAF_API_KEY is set. */
class WangsafOtpTest extends TestCase
{
    use RefreshDatabase;

    private const FORM = [
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
    private function whatsappCode(): string
    {
        preg_match('/\b(\d{6})\b/', Http::recorded()->last()[0]['message'], $match);

        return $match[1];
    }

    public function test_the_code_is_sent_by_whatsapp_and_never_logged(): void
    {
        $this->fakeWangsaf();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$logged) {
            $logged[] = $log->message;
        });

        $this->post('/daftar', self::FORM)->assertRedirect('/daftar/verifikasi');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.wangsaf.com/v1/chat/send-text'
            && $request->hasHeader('Api-Key', 'kunci-uji')
            && $request['target'] === '6281322223333@s.whatsapp.net'
            && str_contains($request['message'], 'Jangan berikan kode ini'));
        Http::assertNotSent(fn (Request $request) => str_contains($request->body(), 'rahasia-123'));
        $this->assertNotContains('Kode OTP pendaftaran', $logged);

        $this->get('/daftar/verifikasi')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('RegisterOtp')->where('channel', 'WhatsApp')->where('demoCode', null));
        $wrong = $this->whatsappCode() === '000000' ? '111111' : '000000';
        $this->post('/daftar/verifikasi', ['code' => $wrong])
            ->assertSessionHasErrors(['code' => 'Kode OTP salah. Periksa kembali pesan WhatsApp Anda.']);

        $this->post('/daftar/verifikasi', ['code' => $this->whatsappCode()])->assertRedirect('/daftar/selesai');
        $this->assertAuthenticatedAs(User::where('username', '6281322223333')->firstOrFail());
    }

    public function test_a_failed_send_keeps_the_patient_on_the_form(): void
    {
        // Wangsaf reports a wrong key as HTTP 200 with status 401 in the body.
        $this->fakeWangsaf(401);

        $this->post('/daftar', self::FORM)->assertSessionHasErrors([
            'phone' => 'Kode OTP gagal dikirim ke WhatsApp +62 813 •••• 3333. Pastikan nomor ini aktif di WhatsApp, lalu coba lagi.',
        ]);
        $this->get('/daftar/verifikasi')->assertRedirect('/daftar');
        $this->assertSame(0, User::count());
    }

    public function test_at_most_five_codes_per_number_per_hour(): void
    {
        $this->fakeWangsaf();
        $this->post('/daftar', self::FORM);
        foreach (range(1, 4) as $resend) {
            $this->travel(61)->seconds();
            $this->post('/daftar/verifikasi/kirim-ulang')->assertSessionHasNoErrors();
        }

        $this->travel(61)->seconds();
        $this->post('/daftar/verifikasi/kirim-ulang')->assertSessionHasErrors('code');
        Http::assertSentCount(5);
    }

    public function test_the_android_api_gets_the_code_by_whatsapp_too(): void
    {
        $this->fakeWangsaf();

        $signup = $this->postJson('/api/daftar', self::FORM)->assertCreated()
            ->assertJson(['channel' => 'WhatsApp', 'demoCode' => null])->json('signup');
        $this->postJson('/api/daftar/verifikasi', ['signup' => $signup, 'code' => $this->whatsappCode()])
            ->assertOk()->assertJson(['account' => ['role' => 'patient']]);
    }
}
