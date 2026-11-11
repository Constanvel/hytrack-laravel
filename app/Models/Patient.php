<?php

namespace App\Models;

use App\Enums\QueueStatus;
use App\Support\HospitalTime;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Patient extends Model
{
    /** @use HasFactory<PatientFactory> */
    use HasFactory;

    protected $primaryKey = 'patient_id';

    const UPDATED_AT = null;

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function queues(): HasMany
    {
        return $this->hasMany(Queue::class, 'patient_id', 'patient_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'recipient_patient_id', 'patient_id');
    }

    /**
     * The visit shown in the patient app: the active one, otherwise the visit
     * that ended today so the patient still sees how it concluded.
     */
    public function currentVisit(): ?Queue
    {
        return $this->queues()
            ->where(fn ($query) => $query
                ->whereIn('queue_status', QueueStatus::active())
                ->orWhere(fn ($ended) => $ended
                    ->whereIn('queue_status', [QueueStatus::Completed, QueueStatus::NoShow])
                    ->where('service_date', HospitalTime::today())))
            ->with(['doctor', 'polyclinic', 'patient', 'pharmacyOrder'])
            ->latest('queue_id')
            ->first();
    }

    /** "Siti A." — how a companion sees the patient, without the full name. */
    public function shortName(): string
    {
        $parts = preg_split('/\s+/', trim($this->full_name));

        return count($parts) > 1 ? $parts[0].' '.mb_substr(end($parts), 0, 1).'.' : $parts[0];
    }

    /** Last four digits only, e.g. "•••• 0842". */
    public function maskedPhone(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->phone_number);

        return strlen($digits) >= 4 ? '•••• '.substr($digits, -4) : null;
    }
}
