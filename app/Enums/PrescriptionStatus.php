<?php

namespace App\Enums;

enum PrescriptionStatus: string
{
    case Received = 'received';
    case Preparing = 'preparing';
    case Checking = 'checking';
    case Ready = 'ready';
    case Completed = 'completed';

    public function next(): ?self
    {
        return match ($this) {
            self::Received => self::Preparing,
            self::Preparing => self::Checking,
            self::Checking => self::Ready,
            self::Ready => self::Completed,
            self::Completed => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Received => 'Resep diterima',
            self::Preparing => 'Obat disiapkan',
            self::Checking => 'Pemeriksaan akhir',
            self::Ready => 'Siap diambil',
            self::Completed => 'Sudah diserahkan',
        };
    }
}
