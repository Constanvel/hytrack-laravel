<?php

namespace App\Models;

use App\Enums\PrescriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preparation progress of one prescription. The medicines are the doctor's prescription
 * items (Examination). verified_at = pharmacist verified the prescription and started,
 * prepared_at = preparation finished (ready), called_at = patient called to the counter.
 */
class PharmacyOrder extends Model
{
    protected $primaryKey = 'pharmacy_order_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'prescription_status' => PrescriptionStatus::class,
            'received_at' => 'datetime',
            'verified_at' => 'datetime',
            'prepared_at' => 'datetime',
            'ready_at' => 'datetime',
            'completed_at' => 'datetime',
            'called_at' => 'datetime',
            'returned_at' => 'datetime',
            'checks' => 'array',
        ];
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class, 'queue_id', 'queue_id');
    }

    /** The staff member behind the latest status change. */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'updated_by_staff_id', 'staff_id');
    }

    /** Number called at the pharmacy counter, e.g. "F-018". */
    public function pickupNumber(): string
    {
        return sprintf('F-%03d', $this->pharmacy_order_id);
    }
}
