<?php

namespace App\Models;

use App\Enums\SupportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A doctor's referral to laboratory or radiology (Figma 47:2 frame 33). */
class SupportRequest extends Model
{
    protected $primaryKey = 'support_request_id';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => SupportStatus::class,
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class, 'queue_id', 'queue_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'requested_by_staff_id', 'staff_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'updated_by_staff_id', 'staff_id');
    }

    /** "PNJ-008". */
    public function number(): string
    {
        return sprintf('PNJ-%03d', $this->support_request_id);
    }

    /** "Laboratorium", from config('hytrack.support_units'). */
    public function unitLabel(): string
    {
        return config("hytrack.support_units.{$this->unit}", ucfirst($this->unit));
    }
}
