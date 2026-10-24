<?php

namespace Database\Factories;

use App\Models\LedgerCategory;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LedgerCategory>
 */
class LedgerCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(), 'scope_id' => fn (array $attributes) => Project::findOrFail($attributes['project_id'])->scope_id,
            'title' => fake()->word(), 'kind' => 'both',
        ];
    }
}
