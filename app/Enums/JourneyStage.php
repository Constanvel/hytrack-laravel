<?php

namespace App\Enums;

/** Position of a stage in patient_journeys.stage_order. */
enum JourneyStage: int
{
    case CheckIn = 1;
    case Consultation = 2;
    case Laboratory = 3;
    case Pharmacy = 4;

    public function label(): string
    {
        return match ($this) {
            self::CheckIn => 'Check-in',
            self::Consultation => 'Pemeriksaan dokter',
            self::Laboratory => 'Laboratorium',
            self::Pharmacy => 'Farmasi',
        };
    }
}
