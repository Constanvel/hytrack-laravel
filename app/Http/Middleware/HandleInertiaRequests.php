<?php

namespace App\Http\Middleware;

use App\Enums\InvitationStatus;
use App\Enums\UserRole;
use App\Models\CompanionShare;
use App\Models\User;
use App\Support\HospitalTime;
use App\Support\VisitView;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $patient = $user?->role === UserRole::Patient ? $user->patient : null;

        return [
            ...parent::share($request),
            'hospital' => config('hytrack.hospital'),
            'demo' => config('hytrack.demo_controls'),
            'pollSeconds' => config('hytrack.poll_seconds'),
            'today' => HospitalTime::dateLabel(HospitalTime::today()),
            'now' => HospitalTime::clock(now()),
            // Server time of this response: the "last successful sync" shown on live screens.
            'syncedAt' => HospitalTime::now()->format('H.i.s'),
            'auth' => $user ? $this->account($user) : null,
            // The patient shell, home, Hya and visit screens all read the current visit.
            'visit' => fn () => VisitView::forPatient($patient?->currentVisit()),
            'unread' => fn () => $patient?->notifications()->whereNull('read_at')->count() ?? 0,
            'following' => fn () => $patient ? CompanionShare::live()->where('status', InvitationStatus::Accepted)
                ->where('companion_user_id', $user->user_id)->count() : 0,
        ];
    }

    /** @return array<string, mixed> */
    private function account(User $user): array
    {
        $isPatient = $user->role === UserRole::Patient;
        $profile = $isPatient ? $user->patient : $user->staff;

        return [
            'role' => $user->role->value,
            'name' => $profile?->full_name ?? $user->username,
            'phone' => $isPatient ? $profile?->maskedPhone() : null,
            'unit' => $isPatient ? null : $profile?->work_unit,
        ];
    }
}
