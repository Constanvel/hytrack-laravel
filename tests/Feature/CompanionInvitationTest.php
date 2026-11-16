<?php

namespace Tests\Feature;

use App\Enums\InvitationStatus;
use App\Models\CompanionShare;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Queue;
use App\Models\Staff;
use App\Models\User;
use App\Services\QueueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class CompanionInvitationTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-04';

    private User $patient;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(self::TODAY.' 08:00', 'Asia/Jakarta'));
        $this->patient = Patient::factory()->create(['full_name' => 'Siti Aminah', 'phone_number' => '+62 812-5550-0842'])->user;
        $this->doctor = Doctor::factory()->create();
    }

    private function book(?User $user = null): Queue
    {
        return app(QueueService::class)->book(($user ?? $this->patient)->patient, $this->doctor, self::TODAY);
    }

    /** Sends an invitation as the patient and returns the token from the one-time link. */
    private function invite(?User $user = null): string
    {
        $this->actingAs($user ?? $this->patient)->from('/pendamping')->post('/pendamping')->assertRedirect('/pendamping');
        $link = null;
        $this->get('/pendamping')->assertInertia(function (AssertableInertia $page) use (&$link) {
            $link = $page->toArray()['flash']['shareLink'];
        });

        return basename($link);
    }

    private function companion(string $name = 'Ahmad Fauzi'): User
    {
        return Patient::factory()->create(['full_name' => $name])->user;
    }

    public function test_invitation_flow_from_link_to_monitoring(): void
    {
        $queue = $this->book();
        $token = $this->invite();

        // Only the hash is stored, and the invitation waits for acceptance.
        $share = CompanionShare::sole();
        $this->assertSame(hash('sha256', $token), $share->access_token_hash);
        $this->assertStringNotContainsString($token, json_encode($share->getAttributes()));
        $this->assertSame(InvitationStatus::Pending, $share->status);
        $this->assertSame('2026-11-04 16:59:59', $share->expires_at->toDateTimeString()); // 23.59 WIB in UTC

        // A guest is asked to sign in and comes back to the invitation afterwards.
        auth()->logout();
        $this->get('/pantauan/'.$token)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('CompanionInvite')->where('invite.patientName', 'Siti A.')->where('canAccept', false));
        $companion = $this->companion();
        $this->post('/masuk', ['username' => $companion->username, 'password' => 'password'])->assertRedirect('/pantauan/'.$token);

        $this->get('/pantauan/'.$token)->assertInertia(fn (AssertableInertia $page) => $page->where('canAccept', true));
        $this->post('/pantauan/'.$token)->assertRedirect('/pendampingan/'.$share->share_id);
        $this->assertSame(InvitationStatus::Accepted, $share->refresh()->status);
        $this->assertSame($companion->user_id, $share->companion_user_id);

        // Progress, place and the real journey; no full name, check-in code or doctor.
        $response = $this->get('/pendampingan/'.$share->share_id)->assertOk();
        $response->assertInertia(fn (AssertableInertia $page) => $page
            ->component('CompanionDashboard')
            ->where('progress.ticket', $queue->queue_number)
            ->where('progress.patientName', 'Siti A.')
            ->where('progress.place', 'Belum check-in')
            ->where('progress.timeline.0.label', 'Menunggu kedatangan')
            ->where('progress.endsAt', 'hari ini pukul 23.59')
            ->missing('progress.checkInCode')
            ->missing('progress.checkInQr')
            ->missing('progress.doctor'));
        $this->assertStringNotContainsString('Aminah', $response->getContent());

        // Check-in by staff shows up as a finished stage.
        app(QueueService::class)->checkIn($queue, Staff::factory()->create());
        $this->get('/pendampingan/'.$share->share_id)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('progress.checkedInAt', '08.00')
            ->where('progress.timeline.0', ['label' => 'Check-in kedatangan', 'time' => '08.00', 'current' => false])
            ->where('progress.timeline.1.label', 'Menunggu pemeriksaan')
            ->where('progress.next', 'pemeriksaan dokter')
            ->where('progress.place', 'Poli '.$this->doctor->polyclinic->polyclinic_name));

        // The link is spent: nobody else can use it, not even to look.
        $this->actingAs($this->companion('Orang Lain'))->get('/pantauan/'.$token)->assertNotFound();
        $this->post('/pantauan/'.$token)->assertNotFound();
    }

    public function test_one_patient_invites_many_and_one_companion_follows_many(): void
    {
        $this->book();
        $first = $this->invite();
        $second = $this->invite();
        $this->assertSame(2, CompanionShare::live()->count());

        $ahmad = $this->companion();
        $rudi = $this->companion('Rudi Hartono');
        $this->actingAs($ahmad)->post('/pantauan/'.$first);
        $this->actingAs($rudi)->post('/pantauan/'.$second);

        // Ahmad also accompanies another patient through that patient's own invitation.
        $other = Patient::factory()->create(['full_name' => 'Budi Santoso'])->user;
        $this->book($other);
        $third = $this->invite($other);
        $this->actingAs($ahmad)->post('/pantauan/'.$third);

        $this->actingAs($ahmad)->get('/pendampingan')->assertInertia(fn (AssertableInertia $page) => $page
            ->component('CompanionList')->has('patients', 2));
        $this->actingAs($rudi)->get('/pendampingan')->assertInertia(fn (AssertableInertia $page) => $page->has('patients', 1));

        // The patient sees both companions by name and status.
        $this->actingAs($this->patient)->get('/pendamping')->assertInertia(fn (AssertableInertia $page) => $page
            ->has('invitations', 2)
            ->where('invitations.0.companion', 'Rudi H.')
            ->where('invitations.0.status', 'accepted'));
    }

    public function test_companions_only_see_visits_they_were_invited_to(): void
    {
        $this->book();
        $token = $this->invite();
        $ahmad = $this->companion();
        $this->actingAs($ahmad)->post('/pantauan/'.$token);
        $share = CompanionShare::sole();

        // Another account cannot open Ahmad's invitation by id, and nobody can accept their own.
        $this->actingAs($this->companion('Rudi Hartono'))->get('/pendampingan/'.$share->share_id)->assertNotFound();
        $ownToken = $this->invite();
        $this->actingAs($this->patient)->post('/pantauan/'.$ownToken)->assertRedirect();
        $this->assertSame(InvitationStatus::Pending, CompanionShare::latest('share_id')->first()->status);

        // Staff accounts cannot accept invitations.
        $this->actingAs(Staff::factory()->create()->user)->post('/pantauan/'.$ownToken)->assertForbidden();
    }

    public function test_patient_revokes_and_access_ends_with_the_visit(): void
    {
        $queue = $this->book();
        $token = $this->invite();
        $ahmad = $this->companion();
        $this->actingAs($ahmad)->post('/pantauan/'.$token);
        $share = CompanionShare::sole();

        $this->actingAs($this->patient)->from('/pendamping')->delete('/pendamping/'.$share->share_id)->assertRedirect('/pendamping');
        $this->assertSame(InvitationStatus::Revoked, $share->refresh()->status);
        $this->actingAs($ahmad)->get('/pendampingan/'.$share->share_id)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('progress', null)->where('ended', 'Dicabut'));

        // A new invitation for the same visit; it ends when the visit is cancelled.
        $again = $this->invite();
        $this->actingAs($ahmad)->post('/pantauan/'.$again);
        app(QueueService::class)->cancel($queue);
        $latest = CompanionShare::latest('share_id')->first();
        $this->assertSame(InvitationStatus::Expired, $latest->status);
        $this->actingAs($ahmad)->get('/pendampingan/'.$latest->share_id)
            ->assertInertia(fn (AssertableInertia $page) => $page->where('ended', 'Berakhir'));
        $this->get('/pendampingan')->assertInertia(fn (AssertableInertia $page) => $page->has('patients', 0));
    }

    public function test_invitations_expire_at_the_end_of_the_day_and_companions_can_leave(): void
    {
        $this->book();
        $pending = $this->invite();
        $accepted = $this->invite();
        $ahmad = $this->companion();
        $this->actingAs($ahmad)->post('/pantauan/'.$accepted);
        $share = CompanionShare::where('status', InvitationStatus::Accepted)->sole();

        $this->actingAs($ahmad)->delete('/pendampingan/'.$share->share_id)->assertRedirect('/pendampingan');
        $this->assertSame(InvitationStatus::Revoked, $share->refresh()->status);

        $this->travelTo(Carbon::parse('2026-11-05 00:01', 'Asia/Jakarta'));
        $this->post('/pantauan/'.$pending)->assertNotFound();
        $this->actingAs($this->patient)->get('/pendamping')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('invitations.1.status', 'expired'));
    }
}
