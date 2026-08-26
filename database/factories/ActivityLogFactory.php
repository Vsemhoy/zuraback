<?php

namespace Database\Factories;

use App\Models\ActivityLog;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
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
            'actor_id' => User::factory(),
            'subject_type' => 'task',
            'subject_id' => Task::factory(),
            'action' => 'created',
        ];
    }
}
