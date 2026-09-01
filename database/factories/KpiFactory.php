<?php

namespace Database\Factories;

use App\Models\Kpi;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Kpi> */
class KpiFactory extends Factory
{
    protected $model = Kpi::class;

    public function definition(): array
    {
        return [
            'scope_id' => Scope::factory(),
            'created_by' => User::factory(),
            'name' => fake()->sentence(3),
            'description' => fake()->sentence(),
            'kind' => 'bonus',
            'points' => 10,
            'minimum_completed_tasks' => 1,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
