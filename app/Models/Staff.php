<?php

namespace App\Models;

use Database\Factories\StaffFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Staff extends Model
{
    /** @use HasFactory<StaffFactory> */
    use HasFactory;

    protected $table = 'staff';

    protected $primaryKey = 'staff_id';

    public $timestamps = false;

    protected $guarded = [];

    public const DESK = 'desk';

    public const DOCTOR = 'doctor';

    public const SUPPORT = 'support';

    public const PHARMACY = 'pharmacy';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id', 'doctor_id');
    }

    /**
     * Which room the account works in (Figma 47:2 sidebars: Ruang petugas, dokter,
     * penunjang, farmasi). A doctor account is linked to its doctor; the other rooms
     * follow the work unit.
     */
    public function room(): string
    {
        $unit = mb_strtolower($this->work_unit);

        return match (true) {
            $this->doctor_id !== null => self::DOCTOR,
            str_starts_with($unit, 'farmasi') => self::PHARMACY,
            collect(config('hytrack.support_units'))->contains(fn (string $label) => str_starts_with($unit, mb_strtolower($label))) => self::SUPPORT,
            default => self::DESK,
        };
    }
}
