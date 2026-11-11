<?php

namespace App\Models;

use App\Enums\PrescriptionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Preparation progress of one prescription; it holds no medicine details.
 * prepared_at = preparation finished, verified_at = pharmacist's final check passed.
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
        ];
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class, 'queue_id', 'queue_id');
    }

    /** Number called at the pharmacy counter, e.g. "F-018". */
    public function pickupNumber(): string
    {
        return sprintf('F-%03d', $this->pharmacy_order_id);
    }
}
