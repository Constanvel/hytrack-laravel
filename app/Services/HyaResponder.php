<?php

namespace App\Services;

use App\Enums\PrescriptionStatus;
use App\Enums\QueueStatus;
use App\Models\Queue;

/**
 * Hya answers from fixed topics using the patient's real queue data.
 * It is rule-based, not an AI model, and never gives medical advice.
 */
class HyaResponder
{
    public function __construct(private readonly WaitEstimator $estimator) {}

    public function reply(string $question, ?Queue $queue): string
    {
        if (preg_match('/medis|sakit|obat|diagnos|resep|dosis/iu', $question)) {
            return 'Untuk keluhan atau pertanyaan medis, silakan hubungi tenaga kesehatan. Hya hanya membantu informasi layanan rumah sakit.';
        }

        if (preg_match('/check|qr|terlambat/iu', $question)) {
            return 'Buka tiket kunjungan, lalu tekan “Tampilkan QR check-in”. Tunjukkan QR atau nomor check-in kepada petugas di meja check-in. Jika terlambat, minta bantuan petugas.';
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

        if (preg_match('/giliran|antre|tunggu|kapan/iu', $question)) {
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
