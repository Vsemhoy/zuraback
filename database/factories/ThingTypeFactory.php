<?php

namespace Database\Factories;

use App\Models\Scope;
use Illuminate\Database\Eloquent\Factories\Factory;

class ThingTypeFactory extends Factory
{
    public function definition(): array
    {
        return ['scope_id' => Scope::factory(), 'name' => fake()->unique()->word(), 'schema' => []];
    }
}
