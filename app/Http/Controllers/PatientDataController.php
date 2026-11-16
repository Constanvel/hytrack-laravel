<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Services\PatientRecords;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Data pasien (Figma 27:61, 27:110, 27:179). */
class PatientDataController extends Controller
{
    public function __construct(private readonly PatientRecords $records) {}

    public function index(Request $request): Response
    {
        return Inertia::render('PatientData', [
            'patients' => array_map(fn (Patient $patient) => $patient->record(), $this->records->list($request->user()->patient)),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('PatientForm', ['patient' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $patient = $this->records->create($request->user()->patient, $request);
        Inertia::flash('status', "Data {$patient->full_name} tersimpan.");

        return redirect('/data-pasien');
    }

    public function edit(Request $request, int $patient): Response
    {
        return Inertia::render('PatientForm', [
            'patient' => $this->records->find($request->user()->patient, $patient)->record(),
        ]);
    }

    public function update(Request $request, int $patient): RedirectResponse
    {
        $this->records->update($this->records->find($request->user()->patient, $patient), $request);
        Inertia::flash('status', 'Perubahan data pasien tersimpan.');

        return redirect('/data-pasien');
    }
}
