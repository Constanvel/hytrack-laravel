<?php

namespace App\Enums;

/** companion_shares.status. Expired is also derived when the visit or the day ends. */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Menunggu diterima',
            self::Accepted => 'Aktif',
            self::Revoked => 'Dicabut',
            self::Expired => 'Berakhir',
        };
    }

    /** @return list<string> */
    public static function live(): array
    {
        return [self::Pending->value, self::Accepted->value];
    }
}
