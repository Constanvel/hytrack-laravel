<?php

namespace Database\Factories;

use App\Models\Polyclinic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Polyclinic>
 */
class PolyclinicFactory extends Factory
{
    public function definition(): array
    {
        return [
            'polyclinic_name' => 'Penyakit Dalam',
            'room_location' => 'Ruang A-204 · Gedung A, lantai 2',
        ];
    }
}
