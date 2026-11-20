<?php

namespace App\Services;

use App\Enums\InvitationStatus;
use App\Enums\UserRole;
use App\Exceptions\QueueRuleException;
use App\Models\CompanionShare;
use App\Models\Patient;
use App\Models\Queue;
use App\Models\User;
use App\Support\HospitalTime;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Family Companion: patient invites → companion accepts with their own account →
 * monitors that one visit → access ends with the visit. Shared by web and API.
 */
class CompanionInvitations
{
    /** The visit a patient can invite companions to. */
    public function visitOf(Patient $patient): Queue
    {
        return $patient->queues()->active()->latest('queue_id')->first()
            ?? throw new QueueRuleException('Tidak ada kunjungan aktif untuk dibagikan.');
    }

    /** @return Collection<int, CompanionShare> newest first, every status */
    public function forVisit(Queue $queue): Collection
    {
        return $queue->companionShares()->with(['companion.patient', 'queue'])->latest('share_id')->get();
    }

    /**
     * A new invitation; several may be live for one visit. The token is returned once
     * and never stored.
     *
     * @return array{CompanionShare, string}
     */
    public function invite(Queue $queue): array
    {
        $token = Str::random(48);
        $share = $queue->companionShares()->create([
            'access_token_hash' => CompanionShare::hashToken($token),
            'status' => InvitationStatus::Pending,
            // Ends with the visit, or at the end of the service day, whichever comes first.
            'expires_at' => HospitalTime::at($queue->service_date, '23:59:59'),
        ]);

        return [$share, $token];
    }

    public function link(string $token): string
    {
        return url('/pantauan/'.$token);
    }

    /**
     * What an invitation shows before it is accepted: no more than the companion will see.
     *
     * @return array{share: CompanionShare, invite: array{patientName: string, clinic: string, date: string}}|null
     */
    public function preview(string $token): ?array
    {
        $share = CompanionShare::findPending($token)?->load('queue.patient', 'queue.polyclinic');

        return $share ? ['share' => $share, 'invite' => [
            'patientName' => $share->queue->patient->shortName(),
            'clinic' => $share->queue->polyclinic->polyclinic_name,
            'date' => HospitalTime::dateLabel($share->queue->service_date),
        ]] : null;
    }

    /** Only another patient account may accept; the patient cannot follow their own visit. */
    public function canAccept(?User $user, ?CompanionShare $share): bool
    {
        return $user?->role === UserRole::Patient && $share !== null && $share->queue->patient->user_id !== $user->user_id;
    }

    /** Accepting binds the invitation to this account; the link stops working for anyone else. */
    public function accept(string $token, User $user): CompanionShare
    {
        return DB::transaction(function () use ($token, $user) {
            $id = CompanionShare::findPending($token)?->share_id ?? throw new NotFoundHttpException;
            $share = CompanionShare::whereKey($id)->lockForUpdate()->with('queue.patient')->first();

            // Two people opening the same link: only the first acceptance counts.
            if ($share->status !== InvitationStatus::Pending) {
                throw new NotFoundHttpException;
            }
            if ($user->role !== UserRole::Patient || $share->queue->patient->user_id === $user->user_id) {
                throw new QueueRuleException('Undangan ini untuk pendamping, bukan untuk pasien sendiri.');
            }

            $share->update([
                'status' => InvitationStatus::Accepted,
                'companion_user_id' => $user->user_id,
                'accepted_at' => now(),
            ]);

            return $share;
        });
    }

    /** The patient revokes one invitation, pending or accepted. */
    public function revoke(Queue $queue, int $shareId): void
    {
        $queue->companionShares()->whereKey($shareId)->whereIn('status', InvitationStatus::live())
            ->update(['status' => InvitationStatus::Revoked, 'revoked_at' => now()]);
    }

    /** What a companion account is following right now (many patients, one invitation each). */
    public function following(User $user): Collection
    {
        return CompanionShare::live()->where('status', InvitationStatus::Accepted)
            ->where('companion_user_id', $user->user_id)
            ->with(['queue.patient', 'queue.polyclinic'])->latest('share_id')->get();
    }

    /** Where a companion lands: the one visit followed, the list when several, null when none. */
    public function home(User $user): ?string
    {
        $following = $this->following($user);

        return match ($following->count()) {
            0 => null,
            1 => '/pendampingan/'.$following->first()->share_id,
            default => '/pendampingan',
        };
    }

    /** One of this account's invitations, in any status; check currentStatus() before showing it. */
    public function followed(User $user, int $shareId): CompanionShare
    {
        return CompanionShare::where('companion_user_id', $user->user_id)->whereKey($shareId)
            ->with(['queue.doctor', 'queue.polyclinic', 'queue.patient', 'queue.pharmacyOrder', 'queue.journeys'])
            ->first() ?? throw new NotFoundHttpException;
    }

    /** The companion gives up their own access. */
    public function stop(User $user, int $shareId): void
    {
        CompanionShare::where('companion_user_id', $user->user_id)->whereKey($shareId)
            ->where('status', InvitationStatus::Accepted)
            ->update(['status' => InvitationStatus::Revoked, 'revoked_at' => now()]);
    }

    /** Row of the patient's invitation list. */
    public static function row(CompanionShare $share): array
    {
        $status = $share->currentStatus();

        return [
            'id' => $share->share_id,
            'status' => $status->value,
            'label' => $status->label(),
            'companion' => $share->companion?->patient?->shortName(),
            'sentAt' => HospitalTime::clock($share->created_at),
            'acceptedAt' => HospitalTime::clock($share->accepted_at),
        ];
    }
}
