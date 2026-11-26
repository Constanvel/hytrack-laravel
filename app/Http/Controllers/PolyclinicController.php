<?php

namespace App\Http\Controllers;

use App\Models\Polyclinic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Master data CRUD for polyclinics, on the staff dashboard ("Data poliklinik").
 * Read is the list in StaffController@index; these routes are behind auth + role:staff.
 */
class PolyclinicController extends Controller
{
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $clinic = Polyclinic::create($this->validated($request));

        return $this->done("Poliklinik {$clinic->polyclinic_name} ditambahkan.");
    }

    public function update(Request $request, Polyclinic $polyclinic): RedirectResponse|JsonResponse
    {
        $polyclinic->update($this->validated($request, $polyclinic));

        return $this->done("Poliklinik {$polyclinic->polyclinic_name} diperbarui.");
    }

    /**
     * Archives the polyclinic, whatever still uses it: it leaves the lists and booking, while
     * doctors and queues keep it, so no visit history is lost. Ongoing queues stay on the
     * live dashboard until staff finish them.
     */
    public function destroy(Polyclinic $polyclinic): RedirectResponse|JsonResponse
    {
        $ongoing = $polyclinic->queues()->ongoing()->count();
        $polyclinic->delete();

        return $this->done("Poliklinik {$polyclinic->polyclinic_name} dihapus dari daftar. Riwayat antreannya tetap tersimpan."
            .($ongoing ? " {$ongoing} antrean yang masih berjalan tetap bisa diselesaikan di dashboard." : ''));
    }

    /** @return array{polyclinic_name: string, room_location: ?string} */
    private function validated(Request $request, ?Polyclinic $current = null): array
    {
        $request->merge([
            'name' => trim((string) $request->input('name')),
            'room' => trim((string) $request->input('room')) ?: null,
        ]);
        $data = $request->validate([
            'name' => [
                'required', 'string', 'min:3', 'max:100',
                // An archived polyclinic frees its name for a new one.
                Rule::unique('polyclinics', 'polyclinic_name')->ignore($current?->polyclinic_id, 'polyclinic_id')->whereNull('archived_at'),
            ],
            'room' => ['nullable', 'string', 'max:100'],
        ], [], ['name' => 'nama poliklinik', 'room' => 'lokasi ruangan']);

        return ['polyclinic_name' => $data['name'], 'room_location' => $data['room']];
    }
}
