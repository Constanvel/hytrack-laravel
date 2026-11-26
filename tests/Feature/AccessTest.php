<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class AccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_sent_to_the_sign_in_page(): void
    {
        foreach (['/', '/kunjungan'] as $url) {
            $this->get($url)->assertRedirect('/masuk');
        }
        // Staff and management sign in on the internal page (Figma 47:2 "Login").
        foreach (['/petugas', '/manajemen'] as $url) {
            $this->get($url)->assertRedirect('/masuk/internal');
        }

        $this->get('/masuk')->assertInertia(fn (AssertableInertia $page) => $page->component('Login'));
    }

    public function test_each_role_signs_in_to_its_own_home(): void
    {
        $patient = Patient::factory()->create()->user;
        $staff = Staff::factory()->create()->user;
        $manager = User::factory()->manager()->create();

        foreach ([[$patient, '/'], [$staff, '/petugas'], [$manager, '/manajemen']] as [$user, $home]) {
            $this->post('/masuk', ['username' => $user->username, 'password' => 'password'])->assertRedirect($home);
            $this->assertAuthenticatedAs($user);
            $this->post('/keluar')->assertRedirect($home === '/' ? '/masuk' : '/masuk/internal');
            $this->assertGuest();
        }
    }

    public function test_sign_in_returns_to_the_requested_page_only_within_the_role(): void
    {
        $staff = Staff::factory()->create()->user;
        $patient = Patient::factory()->create()->user;

        $this->get('/kunjungan')->assertRedirect('/masuk');
        $this->post('/masuk', ['username' => $staff->username, 'password' => 'password'])->assertRedirect('/petugas');
        $this->post('/keluar');

        $this->get('/kunjungan')->assertRedirect('/masuk');
        $this->post('/masuk', ['username' => $patient->username, 'password' => 'password'])->assertRedirect('/kunjungan');
    }

    public function test_wrong_passwords_and_deactivated_accounts_are_rejected(): void
    {
        $user = Patient::factory()->create()->user;

        $this->post('/masuk', ['username' => $user->username, 'password' => 'salah'])
            ->assertSessionHasErrors(['username' => 'Nomor ponsel atau kata sandi salah.']);

        $user->update(['is_active' => false]);
        $this->post('/masuk', ['username' => $user->username, 'password' => 'password'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_patients_sign_in_with_their_phone_number_in_any_format(): void
    {
        $user = Patient::factory()->create(['phone_number' => '+62 812-1111-2222'])->user;

        foreach (['0812 1111 2222', '+62 812-1111-2222', '6281211112222'] as $phone) {
            $this->post('/masuk', ['username' => $phone, 'password' => 'password'])->assertRedirect('/');
            $this->assertAuthenticatedAs($user);
            $this->post('/keluar');
        }

        $this->post('/masuk', ['username' => '0812 9999 0000', 'password' => 'password'])
            ->assertSessionHasErrors(['username' => 'Nomor ponsel atau kata sandi salah.']);
    }

    public function test_sign_up_creates_a_patient_account_and_signs_in(): void
    {
        $this->get('/daftar')->assertInertia(fn (AssertableInertia $page) => $page->component('Register'));

        $this->signUp([
            'name' => '  Budi Santoso ',
            'phone' => '0813-2222-3333',
            'password' => 'rahasia-123',
            'password_confirmation' => 'rahasia-123',
            'terms' => true,
        ])->assertRedirect('/daftar/selesai');
        $this->get('/daftar/selesai')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('RegisterVerified')->where('phone', '+62 813 •••• 3333')->where('next', url('/')));

        $user = User::where('username', '6281322223333')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('patient', $user->role->value);
        $this->assertSame('Budi Santoso', $user->patient->full_name);
        $this->assertSame('+62 813-2222-3333', $user->patient->phone_number);

        $this->post('/keluar');
        $this->post('/masuk', ['username' => '+62 813 2222 3333', 'password' => 'rahasia-123'])->assertRedirect('/');
    }

    public function test_no_account_exists_until_the_phone_is_verified(): void
    {
        $form = ['name' => 'Budi', 'phone' => '0813-2222-3333', 'password' => 'rahasia-123', 'password_confirmation' => 'rahasia-123', 'terms' => '1'];
        $this->get('/daftar/verifikasi')->assertRedirect('/daftar');
        $this->post('/daftar', $form)->assertRedirect('/daftar/verifikasi');
        $this->assertSame(0, User::count());
        $code = $this->sentCode();
        $this->get('/daftar/verifikasi')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('RegisterOtp')->where('phone', '+62 813 •••• 3333')->where('demoCode', null)
            ->where('resendIn', fn ($seconds) => $seconds > 40 && $seconds <= 45));

        // Too early to resend, a wrong code, and a malformed one: still no account.
        $this->post('/daftar/verifikasi/kirim-ulang')->assertSessionHasErrors('code');
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->post('/daftar/verifikasi', ['code' => $wrong])->assertSessionHasErrors(['code' => 'Kode OTP salah. Periksa kembali SMS Anda.']);
        $this->post('/daftar/verifikasi', ['code' => '12'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertSame(0, User::count());

        // Five wrong tries lock the code; a new code after 45 s unlocks it.
        foreach (range(1, 4) as $try) {
            $this->post('/daftar/verifikasi', ['code' => $wrong]);
        }
        $this->post('/daftar/verifikasi', ['code' => $code])->assertSessionHasErrors(['code' => 'Terlalu banyak percobaan. Minta kode baru.']);
        $this->travel(61)->seconds(); // past the 45 s resend wait and the per-minute throttle
        $this->post('/daftar/verifikasi/kirim-ulang')->assertRedirect()->assertSessionHasNoErrors();
        $this->post('/daftar/verifikasi', ['code' => $this->sentCode()])->assertRedirect('/daftar/selesai');
        $this->assertSame(1, User::count());
    }

    public function test_an_expired_code_is_refused_and_demo_mode_shows_the_code(): void
    {
        config(['hytrack.demo_controls' => true]);
        $form = ['name' => 'Budi', 'phone' => '0813-2222-3333', 'password' => 'rahasia-123', 'password_confirmation' => 'rahasia-123', 'terms' => '1'];
        $this->post('/daftar', $form);
        $code = null;
        $this->get('/daftar/verifikasi')->assertInertia(function (AssertableInertia $page) use (&$code) {
            $code = $page->toArray()['props']['demoCode'];
            $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        });
        $this->travel(301)->seconds();
        $this->post('/daftar/verifikasi', ['code' => $code])->assertSessionHasErrors(['code' => 'Kode sudah kedaluwarsa. Minta kode baru.']);
        $this->assertSame(0, User::count());
    }

    public function test_sign_up_validates_its_fields_and_rejects_a_registered_phone(): void
    {
        $this->post('/daftar', [])->assertSessionHasErrors([
            'name' => 'Nama lengkap wajib diisi.',
            'phone' => 'Nomor ponsel wajib diisi.',
            'password' => 'Kata sandi wajib diisi.',
            'terms' => 'Setujui Syarat & Ketentuan dan Kebijakan Privasi untuk melanjutkan.',
        ]);

        $valid = ['name' => 'Budi', 'phone' => '0813-2222-3333', 'password' => 'rahasia-123', 'password_confirmation' => 'rahasia-123', 'terms' => '1'];
        $this->post('/daftar', [...$valid, 'phone' => '12345'])->assertSessionHasErrors('phone');
        $this->post('/daftar', [...$valid, 'password' => 'pendek', 'password_confirmation' => 'pendek'])
            ->assertSessionHasErrors(['password' => 'Kata sandi minimal 8 karakter.']);
        $this->post('/daftar', [...$valid, 'password_confirmation' => 'lain-lagi'])
            ->assertSessionHasErrors(['password' => 'Konfirmasi kata sandi tidak sama.']);

        Patient::factory()->create(['phone_number' => '+62 813-2222-3333']);
        $this->post('/daftar', $valid)->assertSessionHasErrors(['phone' => 'Nomor ponsel sudah terdaftar. Silakan masuk.']);
        $this->assertGuest();
        $this->assertSame(1, Patient::count());
    }

    public function test_roles_cannot_open_each_others_pages(): void
    {
        $patient = Patient::factory()->create()->user;
        $staff = Staff::factory()->create()->user;
        $manager = User::factory()->manager()->create();

        $this->actingAs($patient)->get('/petugas')->assertForbidden();
        $this->actingAs($patient)->get('/manajemen')->assertForbidden();
        $this->actingAs($patient)->get('/manajemen/ekspor')->assertForbidden();
        $this->actingAs($staff)->get('/kunjungan')->assertForbidden();
        $this->actingAs($staff)->get('/manajemen')->assertForbidden();
        $this->actingAs($manager)->get('/petugas')->assertForbidden();
        $this->actingAs($manager)->post('/petugas/check-in', ['code' => 'HT-000001'])->assertForbidden();

        // Staff and managers opening "/" land on their own dashboard.
        $this->actingAs($staff)->get('/')->assertRedirect('/petugas');
        $this->actingAs($manager)->get('/')->assertRedirect('/manajemen');
    }

    public function test_forbidden_pages_use_the_hytrack_error_screen(): void
    {
        $this->actingAs(Patient::factory()->create()->user)
            ->get('/petugas')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('ErrorPage')->where('status', 403));
    }

    public function test_the_password_hash_never_reaches_the_browser(): void
    {
        $user = Patient::factory()->create(['phone_number' => '+62 812-5550-0842'])->user;

        $response = $this->actingAs($user)->get('/');

        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Home')
            ->where('auth.role', 'patient')
            ->where('auth.phone', '•••• 0842')
            ->missing('auth.password_hash'));
        $this->assertStringNotContainsString($user->password_hash, $response->getContent());
        $this->assertStringNotContainsString('5550', $response->getContent());
    }
}
