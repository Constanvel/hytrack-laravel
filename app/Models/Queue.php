<?php

namespace App\Models;

use App\Enums\JourneyStage;
use App\Enums\QueueStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One ticket / visit. service_date stays a plain Y-m-d string so that SQLite and
 * MySQL compare it identically.
 */
class Queue extends Model
{
    protected $primaryKey = 'queue_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'queue_status' => QueueStatus::class,
            'recommended_departure_time' => 'datetime',
            'checkin_deadline' => 'datetime',
            'checkin_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'called_count' => 'integer',
            'estimated_wait_minutes' => 'integer',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'patient_id', 'patient_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id', 'doctor_id');
    }

    public function polyclinic(): BelongsTo
    {
        return $this->belongsTo(Polyclinic::class, 'polyclinic_id', 'polyclinic_id');
    }

    public function journeys(): HasMany
    {
        return $this->hasMany(PatientJourney::class, 'queue_id', 'queue_id')->orderBy('stage_order');
    }

    public function pharmacyOrder(): HasOne
    {
        return $this->hasOne(PharmacyOrder::class, 'queue_id', 'queue_id')->latestOfMany('pharmacy_order_id');
    }

    public function companionShares(): HasMany
    {
        return $this->hasMany(CompanionShare::class, 'queue_id', 'queue_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'queue_id', 'queue_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->whereIn('queue_status', QueueStatus::active());
    }

    public function scopeForDoctorOn(Builder $query, int $doctorId, string $date): void
    {
        $query->where('doctor_id', $doctorId)->where('service_date', $date);
    }

    public function journey(JourneyStage $stage): ?PatientJourney
    {
        return $this->journeys->firstWhere('stage_order', $stage->value);
    }

    /** Manual check-in number shown under the QR, e.g. "HT-000027". */
    public function checkInCode(): string
    {
        return sprintf('HT-%06d', $this->queue_id);
    }

    public static function findByCheckInCode(string $code): ?self
    {
        return preg_match('/^HT-?(\d{1,9})$/i', trim($code), $match)
            ? static::find((int) $match[1])
            : null;
    }
}
