<?php

namespace Database\Factories;

use App\Models\Scope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlanItemFactory extends Factory
{
    public function definition(): array
    {
        return ['scope_id' => Scope::factory(), 'created_by' => User::factory(), 'title' => fake()->sentence(3), 'month' => '2026-10'];
    }
}
