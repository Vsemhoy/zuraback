<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
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
            'title' => fake()->sentence(3),
            'status' => 'planning',
        ];
    }
}
