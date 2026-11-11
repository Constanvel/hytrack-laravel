<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Datetimes are stored in UTC; patients and staff see hospital-local wall-clock time.
 * service_date is the hospital-local calendar date, kept as a Y-m-d string.
 */
final class HospitalTime
{
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(config('hytrack.timezone'));
    }

    public static function today(): string
    {
        return self::now()->toDateString();
    }

    /** A local wall-clock time on a service date, as a UTC instant for storage. */
    public static function at(string $date, string $time): CarbonImmutable
    {
        return CarbonImmutable::parse("$date $time", config('hytrack.timezone'))->utc();
    }

    public static function local(CarbonInterface $at): CarbonImmutable
    {
        return $at->toImmutable()->setTimezone(config('hytrack.timezone'));
    }

    /** "09.05" in hospital time, or null. */
    public static function clock(?CarbonInterface $at): ?string
    {
        return $at ? self::local($at)->format('H.i') : null;
    }

    /** "Rabu, 4 November 2026". */
    public static function dateLabel(string $date): string
    {
        return CarbonImmutable::parse($date)->locale('id')->translatedFormat('l, j F Y');
    }
}
