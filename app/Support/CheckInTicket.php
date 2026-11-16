<?php

namespace App\Support;

use App\Enums\QueueStatus;
use App\Exceptions\QueueRuleException;
use App\Models\Queue;

/**
 * What the check-in QR carries: "HT1.<queue id>.<service date>.<signature>". The signature
 * (HMAC with the app key) stops anyone from printing a QR for another ticket; the date
 * ties the code to one visit. The manual number ("HT-000027") stays unsigned, since a
 * person reads it out at the desk.
 */
final class CheckInTicket
{
    public static function payload(Queue $queue): string
    {
        $date = str_replace('-', '', $queue->service_date);

        return "HT1.{$queue->queue_id}.{$date}.".self::sign($queue->queue_id, $date);
    }

    /**
     * The ticket behind a scanned QR or typed number, if it can be checked in today.
     *
     * @throws QueueRuleException with the reason shown to staff
     */
    public static function resolve(string $code): Queue
    {
        $code = trim($code);

        if (preg_match('/^HT1\.(\d{1,9})\.(\d{8})\.([0-9a-f]{20})$/', $code, $qr)) {
            if (! hash_equals(self::sign((int) $qr[1], $qr[2]), $qr[3])) {
                throw new QueueRuleException('QR tidak valid. Minta pasien membuka ulang tiket di aplikasi.');
            }
            $queue = Queue::find((int) $qr[1]);
            // A QR from an earlier booking of the same ticket id cannot exist, but stay strict.
            if ($queue && str_replace('-', '', $queue->service_date) !== $qr[2]) {
                throw new QueueRuleException('QR tidak sesuai dengan sesi kunjungan.');
            }
        } elseif (preg_match('/^HT-?\d{1,9}$/i', $code)) {
            $queue = Queue::findByCheckInCode($code);
        } else {
            throw new QueueRuleException('Kode tidak dikenali. Pindai QR tiket HyTrack atau ketik nomor check-in.');
        }

        if (! $queue) {
            throw new QueueRuleException('Nomor check-in tidak ditemukan.');
        }

        $today = HospitalTime::today();
        $message = match (true) {
            $queue->queue_status === QueueStatus::Cancelled => 'Kunjungan ini sudah dibatalkan.',
            $queue->service_date < $today => 'Tiket sudah kedaluwarsa. Tanggal kunjungannya '.HospitalTime::dateLabel($queue->service_date).'.',
            $queue->service_date > $today => 'Tiket untuk '.HospitalTime::dateLabel($queue->service_date).'. Check-in dibuka pada tanggal tersebut.',
            $queue->queue_status === QueueStatus::NoShow => 'Tiket ditandai tidak hadir dan tidak dapat dipakai lagi.',
            $queue->queue_status !== QueueStatus::Booked => "Tiket {$queue->queue_number} sudah check-in pukul ".HospitalTime::clock($queue->checkin_at).'.',
            default => null,
        };
        if ($message) {
            throw new QueueRuleException($message);
        }

        return $queue;
    }

    /** What staff check before confirming. Expects doctor and polyclinic to be loaded. */
    public static function summary(Queue $queue): array
    {
        return [
            'code' => $queue->checkInCode(),
            'name' => $queue->patient->full_name,
            'ticket' => $queue->queue_number,
            'clinic' => $queue->polyclinic->polyclinic_name,
            'doctor' => $queue->doctor->doctor_name,
            'schedule' => $queue->doctor->scheduleLabel().($queue->polyclinic->room_location
                ? ' · '.explode(' · ', $queue->polyclinic->room_location)[0] : ''),
            'date' => HospitalTime::dateLabel($queue->service_date),
            'deadline' => HospitalTime::clock($queue->checkin_deadline),
        ];
    }

    private static function sign(int $id, string $date): string
    {
        return substr(hash_hmac('sha256', "checkin|{$id}|{$date}", config('app.key')), 0, 20);
    }
}
