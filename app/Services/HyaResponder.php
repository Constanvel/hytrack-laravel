<?php

namespace App\Services;

use App\Enums\PrescriptionStatus;
use App\Enums\QueueStatus;
use App\Models\Queue;
use App\Support\HospitalTime;

/**
 * Hya, the patient's chat assistant. Medical questions are refused here, before any AI.
 * With Gemini configured, Hya answers free questions from facts Laravel reads from the
 * database (so numbers, rooms and times are never invented); when Gemini is off or
 * fails, the fixed rules below answer the common questions.
 */
class HyaResponder
{
    private const MEDICAL = '/medis|diagnos|dosis|gejala|efek samping|(?<!rumah )sakit|minum obat|obat (?:apa|untuk)/iu';

    public function __construct(
        private readonly WaitEstimator $estimator,
        private readonly GeminiChat $gemini,
    ) {}

    /**
     * @param  list<array{role: string, text: string}>  $history  earlier messages, oldest first
     * @return array{reply: string, ai: bool}
     */
    public function reply(string $question, ?Queue $queue, array $history = []): array
    {
        if (preg_match(self::MEDICAL, $question)) {
            return ['reply' => 'Untuk keluhan atau pertanyaan medis, silakan hubungi tenaga kesehatan. Hya hanya membantu informasi layanan rumah sakit.', 'ai' => false];
        }

        $answer = $this->gemini->answer($this->facts($queue), $history, $question);

        return $answer !== null
            ? ['reply' => $answer, 'ai' => true]
            : ['reply' => $this->rules($question, $queue), 'ai' => false];
    }

    /**
     * Everything Gemini may use. No patient name, phone, NIK or check-in code.
     *
     * @return list<string>
     */
    public function facts(?Queue $queue): array
    {
        $rooms = config('hytrack.rooms');
        $facts = [
            'Sekarang: '.HospitalTime::dateLabel(HospitalTime::today()).', pukul '.HospitalTime::now()->format('H.i').' WIB.',
            "Lokasi layanan: meja check-in di {$rooms['check_in']}; laboratorium di {$rooms['laboratory']}; farmasi di {$rooms['pharmacy']}.",
            'Cara ambil antrean: buka Beranda, tekan Ambil antrean, pilih poliklinik dan jadwal dokter, lalu konfirmasi. Satu akun hanya boleh punya 1 kunjungan aktif.',
            'Cara check-in: buka halaman Kunjungan, tekan "Tampilkan QR check-in", lalu tunjukkan QR atau nomor check-in kepada petugas di meja check-in. Batas check-in '.config('hytrack.check_in_lead_minutes').' menit sebelum jadwal dokter dimulai.',
            'Jika terlambat atau ada kendala, pasien meminta bantuan petugas di meja check-in.',
            'Alur kunjungan: check-in, menunggu dipanggil, pemeriksaan dokter di poliklinik, laboratorium, lalu pengambilan obat di farmasi.',
            'Menu lain di aplikasi: Petunjuk ruangan, Bagikan ke pendamping (keluarga dapat memantau kunjungan dengan akun HyTrack sendiri), Riwayat kunjungan, Notifikasi.',
        ];

        $status = $queue?->queue_status;
        if (! $status?->isActive()) {
            $facts[] = 'Pasien belum punya kunjungan aktif.';

            return $facts;
        }

        $clinicRoom = $queue->polyclinic->room_location ?? 'ruang poliklinik';
        $facts[] = "Kunjungan aktif pasien: nomor antrean {$queue->queue_number}, Poli {$queue->polyclinic->polyclinic_name}, "
            ."{$queue->doctor->doctor_name}, jadwal {$queue->doctor->scheduleLabel()} WIB, "
            .HospitalTime::dateLabel($queue->service_date).'.';
        $facts[] = 'Status kunjungan: '.$status->label().". Ruang poliklinik: {$clinicRoom}.";
        $facts[] = match ($status) {
            QueueStatus::Booked => 'Pasien belum check-in. Batas check-in pukul '.HospitalTime::clock($queue->checkin_deadline)
                .' WIB; disarankan berangkat pukul '.HospitalTime::clock($queue->recommended_departure_time).' WIB.',
            QueueStatus::Waiting => $this->waitingReply($queue),
            QueueStatus::Serving => "Nomor {$queue->queue_number} sedang dipanggil; pasien diminta segera masuk ke {$clinicRoom}.",
            QueueStatus::Laboratory => "Pemeriksaan dokter selesai. Pasien lapor ke {$rooms['laboratory']} dengan menunjukkan tiket {$queue->queue_number}.",
            default => $this->pharmacyReply($queue),
        };

        return $facts;
    }

    /** Fixed answers for the common questions, used when Gemini is off or fails. */
    private function rules(string $question, ?Queue $queue): string
    {
        if (preg_match('/check|qr|terlambat/iu', $question)) {
            return 'Buka tiket kunjungan, lalu tekan “Tampilkan QR check-in”. Tunjukkan QR atau nomor check-in kepada petugas di meja check-in. Jika terlambat, minta bantuan petugas.';
        }

        if (preg_match('/(?:cara|gimana|bagaimana).*?(?:ambil|pesan).*?(?:antre|jadwal)/iu', $question)) {
            return 'Buka Beranda dan pilih Ambil antrean. Pilih poliklinik dan jadwal dokter, lalu konfirmasi. Buka halaman Kunjungan untuk melihat tiket Anda.';
        }

        $status = $queue?->queue_status;

        if (! $status?->isActive()) {
            return 'Belum ada kunjungan aktif. Mulai dari Beranda untuk memilih poliklinik dan jadwal dokter. Ini respons terstruktur, bukan layanan AI.';
        }

        $rooms = config('hytrack.rooms');
        $clinicRoom = $queue->polyclinic->room_location ?? 'ruang poliklinik';

        if (preg_match('/ruang|mana|lokasi|arah/iu', $question)) {
            return match ($status) {
                QueueStatus::Pharmacy => "Tunggu di area {$rooms['pharmacy']}.",
                QueueStatus::Laboratory => "Lokasi berikutnya: {$rooms['laboratory']}. Konfirmasikan petunjuk kepada petugas.",
                QueueStatus::Booked => "Check-in lebih dulu di {$rooms['check_in']}, lalu tunggu di {$clinicRoom}.",
                default => "Tempat menunggu Anda: {$clinicRoom}. Ikuti petunjuk rumah sakit dan arahan petugas.",
            };
        }

        if (preg_match('/giliran|antre|tunggu|kapan|obat|resep/iu', $question)) {
            return match ($status) {
                QueueStatus::Booked => 'Anda belum check-in. Tunjukkan QR atau nomor check-in kepada petugas saat tiba di rumah sakit.',
                QueueStatus::Serving => "Nomor {$queue->queue_number} sedang dipanggil. Silakan menuju {$clinicRoom}.",
                QueueStatus::Laboratory => "Layanan berikutnya adalah {$rooms['laboratory']}. Lapor di meja laboratorium dengan tiket {$queue->queue_number}.",
                QueueStatus::Pharmacy => $this->pharmacyReply($queue),
                default => $this->waitingReply($queue),
            };
        }

        return 'Hya dapat membantu informasi antrean, lokasi layanan, dan cara check-in. Pilih topik tersebut atau hubungi petugas rumah sakit untuk bantuan lain.';
    }

    private function waitingReply(Queue $queue): string
    {
        $ahead = $this->estimator->ahead($queue);
        $range = $this->estimator->range($queue->estimated_wait_minutes);

        return "Ada {$ahead} pasien di depan Anda."
            .($range ? " Perkiraan menunggu {$range} menit." : '')
            .' Estimasi dapat berubah mengikuti pelayanan.';
    }

    private function pharmacyReply(Queue $queue): string
    {
        $order = $queue->pharmacyOrder;

        if (! $order) {
            return 'Resep Anda belum diterima farmasi. Tunggu di area farmasi.';
        }

        return $order->prescription_status === PrescriptionStatus::Ready
            ? "Obat siap diambil. Tunjukkan nomor {$order->pickupNumber()} di loket farmasi."
            : "Status obat: {$order->prescription_status->label()}. Tunggu nomor {$order->pickupNumber()} dipanggil di loket farmasi.";
    }
}
