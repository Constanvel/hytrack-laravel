<?php

namespace Tests\Feature;

use App\Enums\QueueStatus;
use App\Enums\StageStatus;
use App\Exceptions\QueueRuleException;
use App\Models\CompanionShare;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Queue;
use App\Models\Staff;
use App\Services\QueueService;
use App\Support\HospitalTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class QueueServiceTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-04';

    private QueueService $queues;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::TODAY.' 08:00', 'Asia/Jakarta'));
        $this->queues = app(QueueService::class);
        $this->doctor = Doctor::factory()->create();
    }

    private function book(?Doctor $doctor = null, string $date = self::TODAY): Queue
    {
        return $this->queues->book(Patient::factory()->create(), $doctor ?? $this->doctor, $date);
    }

    public function test_booking_issues_a_ticket_with_journey_and_departure_times(): void
    {
        $queue = $this->book();

        $this->assertSame('A-001', $queue->queue_number);
        $this->assertSame(QueueStatus::Booked, $queue->queue_status);
        $this->assertSame(['Check-in', 'Pemeriksaan dokter', 'Laboratorium', 'Farmasi'], $queue->journeys->pluck('stage_name')->all());
        // Derived from the doctor's 09.00 start, so it can never contradict the schedule.
        $this->assertSame('08.45', HospitalTime::clock($queue->checkin_deadline));
        $this->assertSame('08.20', HospitalTime::clock($queue->recommended_departure_time));
        $this->assertSame('booked', $queue->notifications()->value('trigger_type'));
    }

    public function test_ticket_numbers_follow_the_doctor_and_never_repeat(): void
    {
        $second = Doctor::factory()->create([
            'polyclinic_id' => $this->doctor->polyclinic_id,
            'schedule_start' => '13:00:00',
            'schedule_end' => '16:00:00',
        ]);

        $cancelled = $this->book();
        $this->queues->cancel($cancelled);

        $this->assertSame('A-002', $this->book()->queue_number);
        $this->assertSame('B-001', $this->book($second)->queue_number);
        $this->assertSame('12.20', HospitalTime::clock($this->book($second)->recommended_departure_time));
    }

    public function test_a_patient_can_hold_only_one_active_visit(): void
    {
        $patient = Patient::factory()->create();
        $this->queues->book($patient, $this->doctor, self::TODAY);

        $this->expectExceptionObject(new QueueRuleException('Anda sudah memiliki satu kunjungan aktif.'));
        $this->queues->book($patient, $this->doctor, self::TODAY);
    }

    public function test_booking_respects_quota_and_the_booking_window(): void
    {
        config(['hytrack.daily_quota' => 1]);
        $this->book();

        try {
            $this->book();
            $this->fail('Quota was not enforced.');
        } catch (QueueRuleException $e) {
            $this->assertStringContainsString('penuh', $e->getMessage());
        }

        $this->assertSame([self::TODAY, '2026-11-05', '2026-11-06'], $this->queues->bookingDates());
        $this->expectException(QueueRuleException::class);
        $this->book(date: '2026-11-07');
    }

    public function test_stages_cannot_be_skipped(): void
    {
        $queue = $this->book();

        $this->expectException(QueueRuleException::class);
        $this->queues->completeLaboratory($queue);
    }

    public function test_check_in_is_only_possible_on_the_service_date(): void
    {
        $queue = $this->book(date: '2026-11-05');

        $this->expectExceptionObject(new QueueRuleException('Check-in hanya dapat dilakukan pada tanggal layanan.'));
        $this->queues->checkIn($queue);
    }

    public function test_a_visit_advances_to_completion_and_ends_companion_access(): void
    {
        $staff = Staff::factory()->create();
        $patient = Patient::factory()->create();
        $queue = $this->queues->book($patient, $this->doctor, self::TODAY);
        $share = CompanionShare::create([
            'queue_id' => $queue->queue_id,
            'access_token_hash' => CompanionShare::hashToken('token'),
            'expires_at' => now()->addDay(),
        ]);

        $this->queues->checkIn($queue, $staff);
        $this->queues->callNext($this->doctor, self::TODAY, $staff);
        $this->queues->completeConsultation($queue, $staff);
        $this->queues->completeLaboratory($queue, $staff);

        $order = $queue->pharmacyOrder()->firstOrFail();
        foreach (range(1, 4) as $step) {
            $this->queues->advancePharmacy($order, $staff);
        }

        $queue->refresh();
        $this->assertSame(QueueStatus::Completed, $queue->queue_status);
        $this->assertTrue($queue->journeys->every(fn ($stage) => $stage->stage_status === StageStatus::Done));
        $this->assertSame($staff->staff_id, $queue->journeys->last()->updated_by_staff_id);
        $this->assertNotNull($order->refresh()->ready_at);
        $this->assertFalse($share->refresh()->is_active);
        $this->assertNull(CompanionShare::findUsable('token'));
        $this->assertContains('medicine_ready', $queue->notifications()->pluck('trigger_type')->all());

        // A finished visit no longer blocks a new booking.
        $this->assertSame(QueueStatus::Booked, $this->queues->book($patient, $this->doctor, self::TODAY)->queue_status);
    }

    public function test_only_one_patient_is_served_at_a_time_in_check_in_order(): void
    {
        $first = $this->book();
        $second = $this->book();
        $this->queues->checkIn($second);
        $this->travel(2)->minutes();
        $this->queues->checkIn($first);

        $this->assertSame($second->queue_id, $this->queues->callNext($this->doctor, self::TODAY)->queue_id);
        $this->assertSame('up_next', $first->notifications()->latest('notification_id')->value('trigger_type'));

        $this->expectExceptionObject(new QueueRuleException('Selesaikan pasien aktif sebelum memanggil berikutnya.'));
        $this->queues->callNext($this->doctor, self::TODAY);
    }

    public function test_absence_requires_a_repeat_call_first(): void
    {
        $queue = $this->book();
        $this->queues->checkIn($queue);
        $this->queues->callNext($this->doctor, self::TODAY);

        try {
            $this->queues->markNoShow($queue);
            $this->fail('Absence was allowed without a repeat call.');
        } catch (QueueRuleException $e) {
            $this->assertStringContainsString('panggilan ulang', $e->getMessage());
        }

        $this->queues->recall($queue);
        $this->assertSame(QueueStatus::NoShow, $this->queues->markNoShow($queue)->queue_status);
    }

    public function test_cancellation_is_limited_to_before_check_in(): void
    {
        $queue = $this->book();
        $this->queues->checkIn($queue);

        try {
            $this->queues->cancel($queue);
            $this->fail('A checked-in visit was cancelled.');
        } catch (QueueRuleException) {
            // expected
        }

        $this->queues->cancel($queue, force: true);
        $this->assertSame(QueueStatus::Cancelled, $queue->refresh()->queue_status);
    }

    public function test_wait_estimates_adapt_to_actual_consultation_length(): void
    {
        [$served, $waiting, $behind] = [$this->book(), $this->book(), $this->book()];
        foreach ([$served, $waiting, $behind] as $queue) {
            $this->queues->checkIn($queue);
            $this->travel(1)->minutes();
        }

        // Nobody has been examined yet: the default of 5 minutes per patient applies.
        $this->assertSame(5, $waiting->refresh()->estimated_wait_minutes);
        $this->assertSame(10, $behind->refresh()->estimated_wait_minutes);

        $this->queues->callNext($this->doctor, self::TODAY);
        $this->travel(12)->minutes();
        $this->queues->completeConsultation($served);

        // One patient ahead of $behind, and consultations now take 12 minutes.
        $this->assertSame(12, $behind->refresh()->estimated_wait_minutes);
        $this->assertNotNull($behind->last_synced_at);
    }
}
