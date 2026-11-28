<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class PatientDataTest extends TestCase
{
    use RefreshDatabase;

    private Patient $owner;

    private array $form = [
        'name' => 'Rafi Pratama',
        'birth_date' => '2015-03-09',
        'gender' => 'male',
        'phone' => '0812 5550 0842',
        'address' => 'Jl. Melati No. 12, RT 02/RW 03',
        'city' => 'Bandung',
        'postal_code' => '40132',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-04 08:00', 'Asia/Jakarta'));
        $this->owner = Patient::factory()->create([
            'full_name' => 'Siti Aminah',
            'phone_number' => '+62 812-5550-0842',
            'medical_record_number' => '00-08-42',
            'birth_date' => '1988-05-12',
            'gender' => 'female',
        ]);
        $this->actingAs($this->owner->user);
    }

    public function test_the_own_record_is_listed_first_with_masked_identifiers(): void
    {
        $this->get('/data-pasien')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('PatientData')
            ->has('patients', 1)
            ->where('patients.0.self', true)
            ->where('patients.0.recordNumber', '•••• 0842')
            ->where('patients.0.birthLabel', '12 Mei 1988')
            ->where('patients.0.age', 38)
            ->where('patients.0.phone', '0812 5550 0842'));
    }

    public function test_family_members_are_added_validated_and_not_duplicated(): void
    {
        $this->post('/data-pasien', $this->form)->assertRedirect('/data-pasien');
        $child = Patient::where('full_name', 'Rafi Pratama')->sole();
        $this->assertNull($child->user_id);
        $this->assertSame($this->owner->patient_id, $child->managed_by_patient_id);
        $this->assertSame('+62 812-5550-0842', $child->phone_number);

        $this->post('/data-pasien', [...$this->form, 'name' => 'rafi pratama'])->assertSessionHasErrors('name');
        $this->assertSame(2, Patient::count());

        $this->post('/data-pasien', [
            ...$this->form, 'name' => '', 'birth_date' => '2026-11-05', 'gender' => 'x',
            'phone' => '12345', 'address' => '', 'city' => '', 'postal_code' => '401',
        ])->assertSessionHasErrors([
            'name' => 'Nama lengkap wajib diisi.',
            'birth_date' => 'Tanggal lahir tidak boleh di masa depan.',
            'gender' => 'Pilih jenis kelamin.',
            'phone' => 'Gunakan nomor aktif dengan awalan 08 atau +62.',
            'address' => 'Alamat lengkap wajib diisi.',
            'city' => 'Kota / kabupaten wajib diisi.',
            'postal_code' => 'Kode pos terdiri dari 5 angka.',
        ]);
        // Postal code is optional.
        $this->post('/data-pasien', [...$this->form, 'name' => 'Nina Pratama', 'postal_code' => ''])->assertRedirect('/data-pasien');

        $this->get('/data-pasien')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('patients', 3)->where('patients.1.name', 'Rafi Pratama')->where('patients.1.self', false));
    }

    public function test_records_are_updated_only_by_their_account(): void
    {
        $this->post('/data-pasien', $this->form);
        $child = Patient::where('full_name', 'Rafi Pratama')->sole();

        $this->get("/data-pasien/{$child->patient_id}/ubah")->assertInertia(fn (AssertableInertia $page) => $page
            ->component('PatientForm')->where('patient.birthDate', '2015-03-09'));
        $this->put("/data-pasien/{$child->patient_id}", [...$this->form, 'city' => 'Cimahi'])->assertRedirect('/data-pasien');
        $this->assertSame('Cimahi', $child->refresh()->city);

        // Another account sees neither the record nor the form.
        $stranger = Patient::factory()->create()->user;
        $this->actingAs($stranger)->get("/data-pasien/{$child->patient_id}/ubah")->assertNotFound();
        $this->put("/data-pasien/{$child->patient_id}", $this->form)->assertNotFound();
        $this->put("/data-pasien/{$this->owner->patient_id}", $this->form)->assertNotFound();
    }

    public function test_changing_the_own_number_keeps_sign_in_working_and_unique(): void
    {
        $this->owner->user->update(['username' => '6281255500842']);
        $other = Patient::factory()->create(['phone_number' => '+62 813-0000-1111']);

        $this->put("/data-pasien/{$this->owner->patient_id}", [...$this->form, 'name' => 'Siti Aminah', 'phone' => '0813 0000 1111'])
            ->assertSessionHasErrors(['phone' => 'Nomor ponsel sudah dipakai akun lain.']);

        $this->put("/data-pasien/{$this->owner->patient_id}", [
            ...$this->form, 'name' => 'Siti Aminah', 'birth_date' => '1988-05-12', 'gender' => 'female', 'phone' => '+62 813 9999 0000',
        ])->assertRedirect('/data-pasien');
        $this->assertSame('6281399990000', User::find($this->owner->user_id)->username);

        $this->post('/keluar');
        $this->post('/masuk', ['username' => '0813-9999-0000', 'password' => 'password'])->assertRedirect('/');
        $this->assertNotNull($other); // the other account is untouched
    }

    public function test_account_page_links_to_patient_data(): void
    {
        $this->get('/akun')->assertInertia(fn (AssertableInertia $page) => $page->component('Account'));
        $this->get('/data-pasien/tambah')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('PatientForm')->where('patient', null));
    }

    public function test_family_records_are_deleted_only_by_their_account(): void
    {
        $this->post('/data-pasien', $this->form);
        $child = Patient::where('full_name', 'Rafi Pratama')->sole();

        // The account's own record is the account: it cannot be deleted.
        $this->delete("/data-pasien/{$this->owner->patient_id}")->assertSessionHasErrors('patient');
        $this->assertModelExists($this->owner);

        // Another account cannot reach the record at all.
        $stranger = Patient::factory()->create()->user;
        $this->actingAs($stranger)->delete("/data-pasien/{$child->patient_id}")->assertNotFound();
        $this->assertModelExists($child);

        $this->actingAs($this->owner->user)->delete("/data-pasien/{$child->patient_id}")
            ->assertRedirect('/data-pasien')->assertSessionHas('inertia.flash_data.status', 'Data Rafi Pratama dihapus dari daftar Anda.');
        $this->assertModelMissing($child);
        $this->get('/data-pasien')->assertInertia(fn (AssertableInertia $page) => $page->has('patients', 1));
    }
}
