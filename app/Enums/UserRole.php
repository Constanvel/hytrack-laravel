<?php

namespace App\Enums;

enum UserRole: string
{
    case Patient = 'patient';
    case Staff = 'staff';
    case Manager = 'manager';

    /** Landing page after login. */
    public function home(): string
    {
        return match ($this) {
            self::Patient => '/',
            self::Staff => '/petugas',
            self::Manager => '/manajemen',
        };
    }

    /** Whether a page belongs to this role's area, so a stale intended URL is not followed into a 403. */
    public function owns(string $path): bool
    {
        $area = fn (string $home) => $path === $home || str_starts_with($path, $home.'/');

        return match ($this) {
            self::Patient => ! $area('/petugas') && ! $area('/manajemen'),
            default => $area($this->home()),
        };
    }
}
