<?php

namespace Database\Factories;

use App\Models\Darklog;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Darklog> */
class DarklogFactory extends Factory
{
    public function definition(): array
    {
        return ['scope_id' => Scope::factory(), 'created_by' => User::factory(), 'number' => fake()->unique()->numberBetween(1, 1000000), 'title' => fake()->sentence(4)];
    }
}
