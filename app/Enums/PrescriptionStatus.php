<?php

namespace App\Enums;

enum PrescriptionStatus: string
{
    case Received = 'received';
    case Preparing = 'preparing';
    // Kept for rows written before Figma 47:2; the pharmacy flow no longer stops here.
    case Checking = 'checking';
    case Ready = 'ready';
    case Completed = 'completed';
    // Sent back to the prescribing doctor; the doctor's new confirmation reopens it.
    case Returned = 'returned';

    /** Figma 47:2 frames 34–37: belum diproses → diproses → siap diambil → diserahkan. */
    public function next(): ?self
    {
        return match ($this) {
            self::Received => self::Preparing,
            self::Preparing, self::Checking => self::Ready,
            self::Ready => self::Completed,
            self::Completed, self::Returned => null,
        };
    }

    /** What the patient reads (Figma 43:2 frame 08). */
    public function label(): string
    {
        return match ($this) {
            self::Received => 'Resep diterima',
            self::Preparing => 'Obat disiapkan',
            self::Checking => 'Pemeriksaan akhir',
            self::Ready => 'Siap diambil',
            self::Completed => 'Sudah diserahkan',
            self::Returned => 'Resep ditinjau ulang dokter',
        };
    }

    /** What pharmacy staff read (Figma 47:2 frame 22). */
    public function staffLabel(): string
    {
        return match ($this) {
            self::Received => 'Belum diproses',
            self::Preparing, self::Checking => 'Diproses',
            self::Ready => 'Siap diambil',
            self::Completed => 'Diserahkan',
            self::Returned => 'Dikembalikan ke dokter',
        };
    }
}
