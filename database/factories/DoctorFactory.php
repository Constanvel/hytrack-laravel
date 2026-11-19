<?php

namespace Database\Factories;

use App\Models\Doctor;
use App\Models\Polyclinic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Doctor>
 */
class DoctorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'polyclinic_id' => Polyclinic::factory(),
            'doctor_name' => 'dr. '.fake()->name(),
            'schedule_start' => '09:00:00',
            'schedule_end' => '12:00:00',
            'availability_status' => Doctor::AVAILABLE,
        ];
    }
}
