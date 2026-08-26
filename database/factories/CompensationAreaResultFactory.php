<?php

namespace Database\Factories;

use App\Models\CompensationAreaResult;
use App\Models\CompensationPeriod;
use App\Models\ResponsibilityArea;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompensationAreaResult>
 */
class CompensationAreaResultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'compensation_period_id' => CompensationPeriod::factory(),
            'responsibility_area_id' => ResponsibilityArea::factory(),
            'area_name' => fake()->sentence(3),
            'area_kind' => 'bonus',
            'area_points' => 15,
            'minimum_completed_tasks' => 1,
        ];
    }
}
