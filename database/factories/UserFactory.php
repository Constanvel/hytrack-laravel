<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->userName(),
            'password_hash' => 'password',
            'role' => UserRole::Patient,
            'email' => null,
            'is_active' => true,
        ];
    }

    public function staff(): static
    {
        return $this->state(['role' => UserRole::Staff]);
    }

    public function manager(): static
    {
        return $this->state(['role' => UserRole::Manager]);
    }
}
