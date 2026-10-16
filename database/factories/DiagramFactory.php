<?php

namespace Database\Factories;

use App\Models\Diagram;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Diagram> */
class DiagramFactory extends Factory
{
    public function definition(): array
    {
        return ['scope_id' => Scope::factory(), 'created_by' => User::factory(), 'title' => fake()->sentence(3), 'xml' => Diagram::EMPTY_XML];
    }
}
