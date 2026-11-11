<?php

namespace App\Models;

use Database\Factories\PolyclinicFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Polyclinic extends Model
{
    /** @use HasFactory<PolyclinicFactory> */
    use HasFactory;

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
