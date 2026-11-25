<?php

namespace App\Models;

use Database\Factories\PolyclinicFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Deleting a polyclinic archives it (soft delete on archived_at): it leaves the lists and
 * booking, but queues and doctors still read it through withTrashed() relations.
 */
class Polyclinic extends Model
{
    /** @use HasFactory<PolyclinicFactory> */
    use HasFactory, SoftDeletes;

    public const DELETED_AT = 'archived_at';

    protected $primaryKey = 'polyclinic_id';

    public $timestamps = false;

    protected $guarded = [];

    public function doctors(): HasMany
    {
        return $this->hasMany(Doctor::class, 'polyclinic_id', 'polyclinic_id');
    }

    public function queues(): HasMany
    {
        return $this->hasMany(Queue::class, 'polyclinic_id', 'polyclinic_id');
    }
}
