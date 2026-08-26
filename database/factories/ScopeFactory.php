<?php

namespace Database\Factories;

use App\Models\Scope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Scope>
 */
class ScopeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'name' => fake()->words(2, true),
            'slug' => fake()->unique()->slug(),
            'is_active' => true,
        ];
    }
}
