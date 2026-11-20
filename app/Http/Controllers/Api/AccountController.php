<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Patient;
use App\Models\User;
use App\Services\PatientRecords;
use App\Support\VisitView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Android app: sign-in with a bearer token, the account, the current visit and data pasien. */
class AccountController extends Controller
{
    public function __construct(private readonly PatientRecords $records) {}

    /** Same credentials as the web sign-in. Issuing a token replaces the previous one. */
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $username = preg_match('/[a-z]/i', $data['username'])
            ? $data['username']
            : User::whereHas('patient', fn ($patient) => $patient->wherePhone(Patient::normalizePhone($data['username'])))->value('username');
        $user = User::where('username', $username ?? $data['username'])->where('is_active', true)->first();

        if (! $user || ! Hash::check($data['password'], $user->password_hash)) {
            throw ValidationException::withMessages(['username' => 'Nomor ponsel atau kata sandi salah.']);
        }

        $token = Str::random(60);
        $user->forceFill(['api_token_hash' => hash('sha256', $token)])->save();

        return response()->json(['token' => $token, 'account' => $this->account($user)]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->forceFill(['api_token_hash' => null])->save();

        return response()->json(['message' => 'Keluar.']);
    }

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $patient = $user->role === UserRole::Patient ? $user->patient : null;

        return response()->json([
            'account' => $this->account($user),
            'visit' => VisitView::forPatient($patient?->currentVisit()),
        ]);
    }

    public function patients(Request $request): JsonResponse
    {
        return response()->json([
            'patients' => array_map(fn (Patient $patient) => $patient->record(), $this->records->list($request->user()->patient)),
        ]);
    }

    public function storePatient(Request $request): JsonResponse
    {
        return response()->json(['patient' => $this->records->create($request->user()->patient, $request)->record()], 201);
    }

    public function updatePatient(Request $request, int $patient): JsonResponse
    {
        $owner = $request->user()->patient;

        return response()->json(['patient' => $this->records->update($this->records->find($owner, $patient), $request)->record()]);
    }

    public function destroyPatient(Request $request, int $patient): JsonResponse
    {
        $owner = $request->user()->patient;
        $this->records->delete($owner, $this->records->find($owner, $patient));

        return response()->json(['message' => 'Data pasien dihapus.']);
    }

    private function account(User $user): array
    {
        $profile = $user->role === UserRole::Patient ? $user->patient : $user->staff;

        return [
            'role' => $user->role->value,
            'name' => $profile?->full_name ?? $user->username,
            'phone' => $user->role === UserRole::Patient ? $profile?->maskedPhone() : null,
            'unit' => $user->role === UserRole::Patient ? null : $profile?->work_unit,
        ];
    }
}
