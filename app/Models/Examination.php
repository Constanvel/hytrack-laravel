<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The doctor's record of one visit (Figma 47:2 frames 31–32): notes, prescription and the
 * confirmation. Only the visit's own doctor writes it; staff outside the doctor and
 * pharmacy never receive the notes.
 */
class Examination extends Model
{
    protected $primaryKey = 'examination_id';

    const CREATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function queue(): BelongsTo
    {
        return $this->belongsTo(Queue::class, 'queue_id', 'queue_id');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'doctor_id', 'doctor_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class, 'examination_id', 'examination_id')->orderBy('position');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'confirmed_by_staff_id', 'staff_id');
    }
}
