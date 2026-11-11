<?php

namespace App\Models;

use Database\Factories\DoctorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Doctor extends Model
{
    /** @use HasFactory<DoctorFactory> */
    use HasFactory;

    public const AVAILABLE = 'available';

    public const DELAYED = 'delayed';

    public const OFF = 'off';

    protected $primaryKey = 'doctor_id';

    public $timestamps = false;

    protected $guarded = [];

    public function polyclinic(): BelongsTo
    {
        return $this->belongsTo(Polyclinic::class, 'polyclinic_id', 'polyclinic_id');
    }

    public function queues(): HasMany
    {
        return $this->hasMany(Queue::class, 'doctor_id', 'doctor_id');
    }

    /** "09.00–12.00", the format used throughout the patient app. */
    public function scheduleLabel(): string
    {
        $clock = fn (string $time) => str_replace(':', '.', substr($time, 0, 5));

        return $clock($this->schedule_start).'–'.$clock($this->schedule_end);
    }

    /** Ticket letter: A for the first doctor of a polyclinic, B for the second, and so on. */
    public function ticketPrefix(): string
    {
        $position = static::where('polyclinic_id', $this->polyclinic_id)
            ->where('doctor_id', '<', $this->doctor_id)
            ->count();

        return chr(ord('A') + $position % 26);
    }
}
