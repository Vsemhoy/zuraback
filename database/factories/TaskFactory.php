<?php

namespace Database\Factories;

use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Task>
 */
class TaskFactory extends Factory
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
            'title' => fake()->sentence(4),
            'status' => 'todo',
        ];
    }
}
