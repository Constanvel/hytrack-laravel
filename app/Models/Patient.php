<?php

namespace App\Models;

use App\Enums\QueueStatus;
use App\Support\HospitalTime;
use Database\Factories\PatientFactory;
use Illuminate\Database\Eloquent\Builder;
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

    protected function casts(): array
    {
        return ['birth_date' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /** The account holder who added this family member. */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'managed_by_patient_id', 'patient_id');
    }

    /** Family members this account holder manages. */
    public function dependents(): HasMany
    {
        return $this->hasMany(self::class, 'managed_by_patient_id', 'patient_id');
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
     * that ended today so the patient still sees how it concluded. A booking that
     * staff cancelled stays visible until its service date so the patient sees why.
     */
    public function currentVisit(): ?Queue
    {
        $today = HospitalTime::today();

        return $this->queues()
            ->where(fn ($query) => $query
                ->where(fn ($active) => $active
                    ->whereIn('queue_status', QueueStatus::active())
                    ->where('service_date', '>=', $today))
                ->orWhere(fn ($ended) => $ended
                    ->whereIn('queue_status', [QueueStatus::Completed, QueueStatus::NoShow])
                    ->where('service_date', $today))
                ->orWhere(fn ($cancelled) => $cancelled
                    ->where('queue_status', QueueStatus::Cancelled)
                    ->whereNotNull('cancelled_by_staff_id')
                    ->where('service_date', '>=', $today)))
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

    /** Digits with the 62 country code, so "0812-3456-7890" and "+62 812 3456 7890" match. */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        return str_starts_with($digits, '0') ? '62'.substr($digits, 1) : $digits;
    }

    /** "6281234567890" → "+62 812-3456-7890", the format the rest of the data uses. */
    public static function formatPhone(string $digits): string
    {
        $local = substr($digits, 2);

        return '+62 '.substr($local, 0, 3).'-'.substr($local, 3, 4).'-'.substr($local, 7);
    }

    /** Matches a normalized number against phone_number whatever its separators. */
    public function scopeWherePhone(Builder $query, string $digits): void
    {
        $column = "replace(replace(replace(replace(replace(replace(phone_number, ' ', ''), '-', ''), '+', ''), '.', ''), '(', ''), ')', '')";
        // ponytail: a full-table expression scan, fine for one hospital; add a normalized indexed column if patients grow large.
        $query->whereRaw("$column = ?", [$digits]);
    }

    /** Last four digits only, e.g. "•••• 0842". */
    public function maskedPhone(): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $this->phone_number);

        return strlen($digits) >= 4 ? '•••• '.substr($digits, -4) : null;
    }

    /** Data pasien card and form values (Figma 27:61 / 27:179). */
    public function record(): array
    {
        $digits = preg_replace('/\D/', '', (string) $this->medical_record_number);

        return [
            'id' => $this->patient_id,
            'self' => $this->user_id !== null,
            'name' => $this->full_name,
            'recordNumber' => strlen($digits) >= 4 ? '•••• '.substr($digits, -4) : null,
            'birthDate' => $this->birth_date?->toDateString(),
            'birthLabel' => $this->birth_date?->locale('id')->translatedFormat('j F Y'),
            'age' => $this->birth_date ? (int) $this->birth_date->diffInYears(HospitalTime::now()) : null,
            'gender' => $this->gender,
            'phone' => $this->phone_number ? self::localPhone($this->phone_number) : null,
            'address' => $this->address,
            'city' => $this->city,
            'postalCode' => $this->postal_code,
        ];
    }

    /** "+62 812-3456-7890" → "0812 3456 7890", how the Figma shows numbers. */
    public static function localPhone(string $phone): string
    {
        $local = '0'.substr(self::normalizePhone($phone), 2);

        return trim(substr($local, 0, 4).' '.substr($local, 4, 4).' '.substr($local, 8));
    }
}
