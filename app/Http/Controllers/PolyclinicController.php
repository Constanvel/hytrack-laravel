<?php

namespace App\Http\Controllers;

use App\Models\Polyclinic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Master data CRUD for polyclinics, on the staff dashboard ("Data poliklinik").
 * Read is the list in StaffController@index; these routes are behind auth + role:staff.
 */
class PolyclinicController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $clinic = Polyclinic::create($this->validated($request));

        return $this->done("Poliklinik {$clinic->polyclinic_name} ditambahkan.");
    }

    public function update(Request $request, Polyclinic $polyclinic): RedirectResponse
    {
        $polyclinic->update($this->validated($request, $polyclinic));

        return $this->done("Poliklinik {$polyclinic->polyclinic_name} diperbarui.");
    }

    /** Doctors and queues keep their polyclinic (restrict on delete), so only unused ones go. */
    public function destroy(Polyclinic $polyclinic): RedirectResponse
    {
        $doctors = $polyclinic->doctors()->count();
        $queues = $polyclinic->queues()->count();
        if ($doctors || $queues) {
            Inertia::flash('error', "Poliklinik {$polyclinic->polyclinic_name} tidak dapat dihapus: masih dipakai {$doctors} dokter dan {$queues} antrean.");

            return back();
        }

        $polyclinic->delete();

        return $this->done("Poliklinik {$polyclinic->polyclinic_name} dihapus.");
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
                Rule::unique('polyclinics', 'polyclinic_name')->ignore($current?->polyclinic_id, 'polyclinic_id'),
            ],
            'room' => ['nullable', 'string', 'max:100'],
        ], [], ['name' => 'nama poliklinik', 'room' => 'lokasi ruangan']);

        return ['polyclinic_name' => $data['name'], 'room_location' => $data['room']];
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('status', $message);

        return back();
    }
}
