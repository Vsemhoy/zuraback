<?php

namespace Database\Factories;

use App\Models\EntityLink;
use App\Models\Event;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EntityLink>
 */
class EntityLinkFactory extends Factory
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
            'source_type' => 'task',
            'source_id' => Task::factory(),
            'target_type' => 'event',
            'target_id' => Event::factory(),
            'relation' => 'related',
            'created_by' => User::factory(),
        ];
    }
}
