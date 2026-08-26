<?php

namespace Database\Factories;

use App\Models\ResponsibilityArea;
use App\Models\ResponsibilityAreaAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ResponsibilityAreaAssignment>
 */
class ResponsibilityAreaAssignmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'responsibility_area_id' => ResponsibilityArea::factory(),
            'user_id' => User::factory(),
            'assigned_by' => User::factory(),
            'active_from' => now()->startOfMonth(),
        ];
    }
}
