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

    /** A visit only moves forward one stage at a time; stages cannot be skipped. */
    public function canBecome(self $next): bool
    {
        return in_array($next, match ($this) {
            self::Booked => [self::Waiting, self::Cancelled],
            self::Waiting => [self::Serving],
            self::Serving => [self::Laboratory, self::NoShow],
            self::Laboratory => [self::Pharmacy],
            self::Pharmacy => [self::Completed],
            default => [],
        }, true);
    }
}
