<?php

namespace App\Http\Controllers;

use App\Enums\SupportStatus;
use App\Models\Queue;
use App\Models\Staff;
use App\Models\SupportRequest;
use App\Services\QueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Figma 47:2 frames 31–33: the doctor's examination and prescription, and support services.
 * Behind auth + role:staff; each action also checks the room (doctor of the visit, support
 * staff). Every change goes through QueueService.
 */
class ClinicalController extends Controller
{
    public function __construct(private readonly QueueService $queues) {}

    /** "Simpan draf". */
    public function save(Request $request, Queue $queue): RedirectResponse|JsonResponse
    {
        $this->queues->saveExamination($queue, $this->doctor($request), $this->examination($request));

        return $this->done("Draf pemeriksaan {$queue->queue_number} tersimpan.");
    }

    /** "Konfirmasi & kirim resep": saves what is on screen, then confirms. */
    public function confirm(Request $request, Queue $queue): RedirectResponse|JsonResponse
    {
        $doctor = $this->doctor($request);
        $request->validate(['examined' => ['accepted']], ['examined.accepted' => 'Tandai pasien sudah diperiksa sebelum mengonfirmasi.']);
        $this->queues->saveExamination($queue, $doctor, $this->examination($request));
        $queue = $this->queues->confirmExamination($queue, $doctor);
        $order = $queue->pharmacyOrder;

        return $this->done($order
            ? "Pemeriksaan {$queue->queue_number} dikonfirmasi. Resep {$order->pickupNumber()} dikirim ke farmasi."
            : "Pemeriksaan {$queue->queue_number} dikonfirmasi tanpa resep. Kunjungan selesai.");
    }

    /** "Buat permintaan penunjang": the draft is kept, the patient goes to support services. */
    public function refer(Request $request, Queue $queue): RedirectResponse|JsonResponse
    {
        $doctor = $this->doctor($request);
        $data = $request->validate([
            'unit' => ['required', Rule::in(array_keys(config('hytrack.support_units')))],
            'test' => ['required', 'string', 'min:3', 'max:100'],
        ], [
            'unit.required' => 'Pilih unit penunjang.',
            'test.required' => 'Tuliskan jenis pemeriksaan.',
            'test.min' => 'Jenis pemeriksaan minimal 3 karakter.',
        ]);
        $this->queues->saveExamination($queue, $doctor, $this->examination($request));
        $support = $this->queues->refer($queue, $doctor, $data['unit'], $data['test']);

        return $this->done("Permintaan {$support->number()} dikirim ke {$support->unitLabel()}.");
    }

    /** "Simpan status": Menunggu / Diproses, note and result file. */
    public function updateSupport(Request $request, SupportRequest $support): RedirectResponse|JsonResponse
    {
        $data = $this->supportInput($request, ['required', Rule::in([SupportStatus::Waiting->value, SupportStatus::Processing->value])]);
        $support = $this->queues->updateSupport($support, $this->staff($request, Staff::SUPPORT),
            SupportStatus::from($data['status']), $data['note'] ?? null, $request->file('result'));

        return $this->done("Status {$support->number()} tersimpan.");
    }

    /** "Konfirmasi selesai & kirim hasil". */
    public function completeSupport(Request $request, SupportRequest $support): RedirectResponse|JsonResponse
    {
        $data = $this->supportInput($request, ['nullable']);
        $support = $this->queues->completeSupport($support, $this->staff($request, Staff::SUPPORT), $data['note'] ?? null, $request->file('result'));

        return $this->done("Hasil {$support->number()} dikirim ke dokter pengirim.");
    }

    /** The result document: support staff, and the doctor of that visit. Never a public URL. */
    public function result(Request $request, SupportRequest $support): StreamedResponse
    {
        $staff = $this->staff($request);
        abort_unless($staff->room() === Staff::SUPPORT || $staff->doctor_id === $support->queue->doctor_id, 403);
        $disk = Storage::disk(config('hytrack.support_result_disk'));
        abort_unless($support->result_path && $disk->exists($support->result_path), 404);

        return $disk->download($support->result_path, $support->result_name, [
            'Content-Type' => $support->result_mime ?? 'application/octet-stream',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** @return array{notes: ?string, allergy: ?string, pharmacy_note: ?string, items?: list<array<string, ?string>>} */
    private function examination(Request $request): array
    {
        return $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
            'allergy' => ['nullable', 'string', 'max:120'],
            'pharmacy_note' => ['nullable', 'string', 'max:255'],
            'items' => ['sometimes', 'array', 'max:20'],
            'items.*.name' => ['required', 'string', 'max:100'],
            'items.*.form' => ['nullable', 'string', 'max:60'],
            'items.*.quantity' => ['required', 'string', 'max:40'],
            'items.*.dosage' => ['required', 'string', 'max:100'],
            'items.*.timing' => ['nullable', 'string', 'max:60'],
        ], [
            'notes.max' => 'Catatan pemeriksaan maksimal 2000 karakter.',
            'items.max' => 'Resep maksimal 20 obat.',
            'items.*.name.required' => 'Nama obat wajib diisi.',
            'items.*.quantity.required' => 'Jumlah obat wajib diisi.',
            'items.*.dosage.required' => 'Aturan pakai wajib diisi.',
        ]);
    }

    /** @return array{status?: string, note?: ?string} */
    private function supportInput(Request $request, array $statusRule): array
    {
        return $request->validate([
            'status' => $statusRule,
            'note' => ['nullable', 'string', 'max:1000'],
            'result' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:'.config('hytrack.support_result_max_kb')],
        ], [
            'result.mimes' => 'Hasil pemeriksaan harus berupa PDF, JPG, atau PNG.',
            'result.max' => 'Ukuran hasil pemeriksaan maksimal 5 MB.',
        ]);
    }

    private function doctor(Request $request): Staff
    {
        return $this->staff($request, Staff::DOCTOR);
    }
}
