<?php

namespace App\Enums;

/** support_requests.status (Figma 47:2 frame 33: Menunggu · Diproses · Selesai). */
enum SupportStatus: string
{
    case Waiting = 'waiting';
    case Processing = 'processing';
    case Done = 'done';

    public function label(): string
    {
        return match ($this) {
            self::Waiting => 'Menunggu',
            self::Processing => 'Diproses',
            self::Done => 'Selesai',
        };
    }
}
