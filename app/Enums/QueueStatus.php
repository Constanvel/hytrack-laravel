<?php

namespace App\Enums;

enum QueueStatus: string
{
    case Booked = 'booked';
    case Waiting = 'waiting';
    case Serving = 'serving';
    case Laboratory = 'laboratory';
    case Pharmacy = 'pharmacy';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';

    /**
     * Statuses that count as the single active visit of a patient.
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Booked, self::Waiting, self::Serving, self::Laboratory, self::Pharmacy];
    }

    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    /** Status shown to staff and patients. "Belum check-in" and "Tidak hadir" are different things. */
    public function label(): string
    {
        return match ($this) {
            self::Booked => 'Belum check-in',
            self::Waiting => 'Sudah check-in',
            self::Serving => 'Dipanggil',
            self::Laboratory => 'Laboratorium',
            self::Pharmacy => 'Farmasi',
            self::Completed => 'Selesai',
            self::NoShow => 'Tidak hadir',
            self::Cancelled => 'Dibatalkan',
        };
    }

    /** A visit only moves forward one stage at a time; stages cannot be skipped. */
    public function canBecome(self $next): bool
    {
        return in_array($next, match ($this) {
            // Booked → NoShow only once the check-in deadline has passed (QueueService::markNoShow).
            self::Booked => [self::Waiting, self::Cancelled, self::NoShow],
            self::Waiting => [self::Serving],
            self::Serving => [self::Laboratory, self::NoShow],
            self::Laboratory => [self::Pharmacy],
            self::Pharmacy => [self::Completed],
            default => [],
        }, true);
    }
}
