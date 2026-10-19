<?php

namespace Database\Factories;

use App\Models\ContentNavigationLink;
use App\Models\Diagram;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentNavigationLink>
 */
class ContentNavigationLinkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope_id' => Scope::factory(), 'created_by' => User::factory(),
            'source_type' => 'diagram', 'source_id' => Diagram::factory(),
            'target_type' => 'task', 'target_id' => Task::factory(), 'label' => null,
        ];
    }
}
