<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrescriptionItem extends Model
{
    protected $primaryKey = 'prescription_item_id';

    public $timestamps = false;

    protected $guarded = [];

    /** @return array<string, string|null> */
    public function row(): array
    {
        return [
            'name' => $this->medicine_name,
            'form' => $this->dosage_form,
            'quantity' => $this->quantity,
            'dosage' => $this->dosage,
            'timing' => $this->timing,
        ];
    }
}
