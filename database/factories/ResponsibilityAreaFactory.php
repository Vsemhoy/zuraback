<?php

namespace Database\Factories;

use App\Models\ResponsibilityArea;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResponsibilityArea>
 */
class ResponsibilityAreaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope_id' => Scope::factory(),
            'created_by' => User::factory(),
            'name' => fake()->sentence(3),
            'kind' => 'bonus',
            'points' => 15,
            'minimum_completed_tasks' => 1,
        ];
    }
}
