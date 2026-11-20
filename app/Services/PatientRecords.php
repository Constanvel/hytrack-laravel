<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\User;
use App\Support\HospitalTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Data pasien: the account holder's own record plus the family members they manage.
 * Shared by the web screens and the Android API.
 */
class PatientRecords
{
    /** @return list<Patient> own record first */
    public function list(Patient $owner): array
    {
        return [$owner, ...$owner->dependents()->orderBy('patient_id')->get()];
    }

    /** Only the account holder's own record or one they manage; anything else does not exist for them. */
    public function find(Patient $owner, int $id): Patient
    {
        $patient = Patient::find($id);

        if (! $patient || ($patient->patient_id !== $owner->patient_id && $patient->managed_by_patient_id !== $owner->patient_id)) {
            throw new NotFoundHttpException;
        }

        return $patient;
    }

    public function create(Patient $owner, Request $request): Patient
    {
        $data = $this->validate($request);

        // The same person entered twice under one account is the same record.
        $duplicate = $owner->dependents()->whereRaw('lower(full_name) = ?', [mb_strtolower($data['full_name'])])
            ->whereDate('birth_date', $data['birth_date'])->exists()
            || (mb_strtolower($owner->full_name) === mb_strtolower($data['full_name'])
                && $owner->birth_date?->toDateString() === $data['birth_date']);
        if ($duplicate) {
            throw ValidationException::withMessages(['name' => 'Data pasien ini sudah tersimpan. Ubah data yang ada bila perlu.']);
        }

        return $owner->dependents()->create($data);
    }

    public function update(Patient $patient, Request $request): Patient
    {
        $data = $this->validate($request, $patient);

        DB::transaction(function () use ($patient, $data) {
            $patient->update($data);

            // The own number is also the sign-in name of accounts created by sign-up.
            $user = $patient->user;
            if ($user && preg_match('/^\d+$/', $user->username)) {
                $user->update(['username' => Patient::normalizePhone($data['phone_number'])]);
            }
        });

        return $patient->refresh();
    }

    /**
     * Removes a family member from the account's list. The account's own record is the
     * account itself and cannot go. Service history is never deleted: a record with visits
     * or notifications is only detached from this account, otherwise it is deleted.
     */
    public function delete(Patient $owner, Patient $patient): void
    {
        if ($patient->patient_id === $owner->patient_id || $patient->user_id) {
            throw ValidationException::withMessages(['patient' => 'Data pasien pemilik akun tidak dapat dihapus.']);
        }

        DB::transaction(function () use ($patient) {
            if ($patient->queues()->exists() || $patient->notifications()->exists()) {
                $patient->update(['managed_by_patient_id' => null]);
            } else {
                $patient->delete();
            }
        });
    }

    /** @return array<string, mixed> column => value */
    private function validate(Request $request, ?Patient $patient = null): array
    {
        $request->merge([
            'phone' => Patient::normalizePhone((string) $request->input('phone')),
            'postal_code' => $request->filled('postal_code') ? trim((string) $request->input('postal_code')) : null,
        ]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'birth_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:'.HospitalTime::today()],
            'gender' => ['required', Rule::in(['female', 'male'])],
            'phone' => ['required', 'regex:/^628\d{7,11}$/'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'postal_code' => ['nullable', 'digits:5'],
        ], [
            'name.required' => 'Nama lengkap wajib diisi.',
            'name.max' => 'Nama lengkap maksimal 100 karakter.',
            'birth_date.required' => 'Tanggal lahir wajib diisi.',
            'birth_date.date_format' => 'Masukkan tanggal lahir yang valid.',
            'birth_date.after_or_equal' => 'Masukkan tanggal lahir yang valid.',
            'birth_date.before_or_equal' => 'Tanggal lahir tidak boleh di masa depan.',
            'gender.required' => 'Pilih jenis kelamin.',
            'gender.in' => 'Pilih jenis kelamin.',
            'phone.required' => 'Nomor ponsel wajib diisi.',
            'phone.regex' => 'Gunakan nomor aktif dengan awalan 08 atau +62.',
            'address.required' => 'Alamat lengkap wajib diisi.',
            'address.max' => 'Alamat maksimal 255 karakter.',
            'city.required' => 'Kota / kabupaten wajib diisi.',
            'city.max' => 'Kota / kabupaten maksimal 100 karakter.',
            'postal_code.digits' => 'Kode pos terdiri dari 5 angka.',
        ]);

        // An account's own number signs it in, so no other account may use it.
        if ($patient?->user_id) {
            $taken = Patient::whereNotNull('user_id')->where('patient_id', '!=', $patient->patient_id)->wherePhone($data['phone'])->exists()
                || User::where('username', $data['phone'])->where('user_id', '!=', $patient->user_id)->exists();
            if ($taken) {
                throw ValidationException::withMessages(['phone' => 'Nomor ponsel sudah dipakai akun lain.']);
            }
        }

        return [
            'full_name' => trim($data['name']),
            'birth_date' => $data['birth_date'],
            'gender' => $data['gender'],
            'phone_number' => Patient::formatPhone($data['phone']),
            'address' => trim($data['address']),
            'city' => trim($data['city']),
            'postal_code' => $data['postal_code'],
        ];
    }
}
