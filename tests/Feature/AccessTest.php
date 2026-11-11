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
        foreach (['/', '/kunjungan', '/petugas', '/manajemen'] as $url) {
            $this->get($url)->assertRedirect('/masuk');
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
            $this->post('/keluar')->assertRedirect('/masuk');
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
            ->assertSessionHasErrors(['username' => 'Nama pengguna atau kata sandi salah.']);

        $user->update(['is_active' => false]);
        $this->post('/masuk', ['username' => $user->username, 'password' => 'password'])
            ->assertSessionHasErrors('username');
        $this->assertGuest();
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
