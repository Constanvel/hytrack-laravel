<?php

namespace Tests\Feature;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Queue;
use App\Models\Staff;
use App\Services\QueueService;
use App\Support\CheckInTicket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CheckInScanTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-04';

    private Staff $staff;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::TODAY.' 08:30', 'Asia/Jakarta'));
        $this->staff = Staff::factory()->create();
        $this->doctor = Doctor::factory()->create(['doctor_name' => 'dr. Rina Kusuma, Sp.PD']);
    }

    private function ticket(string $date = self::TODAY): Queue
    {
        return app(QueueService::class)->book(Patient::factory()->create(['full_name' => 'Siti Aminah']), $this->doctor, $date);
    }

    /** POSTs a code from the scanner page and returns what the page receives. */
    private function scan(string $code): array
    {
        $this->actingAs($this->staff->user)->from('/petugas')->post('/petugas/scan', ['code' => $code])->assertRedirect('/petugas');
        $flash = [];
        $this->get('/petugas')->assertInertia(function (AssertableInertia $page) use (&$flash) {
            $flash = $page->toArray()['flash'];
        });

        return $flash;
    }

    public function test_a_scanned_qr_shows_the_ticket_and_check_in_needs_confirmation(): void
    {
        $queue = $this->ticket();
        $payload = CheckInTicket::payload($queue);
        $this->assertMatchesRegularExpression('/^HT1\.\d+\.20261104\.[0-9a-f]{20}$/', $payload);

        $flash = $this->scan($payload);
        $this->assertSame('Siti Aminah', $flash['scan']['name']);
        $this->assertSame($queue->queue_number, $flash['scan']['ticket']);
        $this->assertSame('08.45', $flash['scan']['deadline']);
        $this->assertSame('booked', $queue->refresh()->queue_status->value); // scanning changes nothing

        $this->post('/petugas/check-in', ['code' => $payload])->assertRedirect();
        $this->assertSame('waiting', $queue->refresh()->queue_status->value);
        $this->assertSame($this->staff->staff_id, $queue->journeys()->first()->updated_by_staff_id);

        // The same QR again is reported as used, and is not recorded twice.
        $this->assertStringStartsWith("Tiket {$queue->queue_number} sudah check-in pukul 08.30", $this->scan($payload)['error']);
        $this->post('/petugas/check-in', ['code' => $payload]);
        $this->assertSame(1, $queue->notifications()->where('trigger_type', 'checked_in')->count());
    }

    public function test_forged_expired_early_and_unknown_codes_are_rejected(): void
    {
        $queue = $this->ticket();
        $payload = CheckInTicket::payload($queue);

        $forged = substr($payload, 0, -1).(str_ends_with($payload, '0') ? '1' : '0');
        $this->assertStringStartsWith('QR tidak valid', $this->scan($forged)['error']);
        // A signature for another date does not fit this ticket's session.
        $otherDay = str_replace('.20261104.', '.20261105.', $payload);
        $this->assertStringStartsWith('QR tidak valid', $this->scan($otherDay)['error']);
        $this->assertSame('Kode tidak dikenali. Pindai QR tiket HyTrack atau ketik nomor check-in.', $this->scan('https://contoh.id')['error']);
        $this->assertSame('Nomor check-in tidak ditemukan.', $this->scan('HT-999999')['error']);

        $later = $this->ticket('2026-11-06');
        $this->assertStringStartsWith('Tiket untuk Jumat, 6 November 2026', $this->scan(CheckInTicket::payload($later))['error']);

        $this->travelTo(Carbon::parse('2026-11-05 08:00', 'Asia/Jakarta'));
        $this->assertStringStartsWith('Tiket sudah kedaluwarsa', $this->scan($payload)['error']);
        $this->assertSame('booked', $queue->refresh()->queue_status->value);
    }

    public function test_the_manual_number_still_works_and_patients_cannot_scan(): void
    {
        $queue = $this->ticket();
        $this->assertSame($queue->queue_number, $this->scan($queue->checkInCode())['scan']['ticket']);

        $this->actingAs($queue->patient->user)->post('/petugas/scan', ['code' => $queue->checkInCode()])->assertForbidden();
        $this->actingAs($queue->patient->user)->get('/kunjungan')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('visit.checkInQr', CheckInTicket::payload($queue)));
    }
}
