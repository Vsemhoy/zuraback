<?php

namespace Database\Factories;

use App\Models\Comment;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Comment>
 */
class CommentFactory extends Factory
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
            'commentable_type' => 'task',
            'commentable_id' => Task::factory(),
            'created_by' => User::factory(),
            'content' => fake()->paragraph(),
        ];
    }
}
