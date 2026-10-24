<?php

namespace Database\Factories;

use App\Models\LedgerAccount;
use App\Models\Project;
use App\Models\Scope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LedgerAccount>
 */
class LedgerAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(), 'scope_id' => fn (array $attributes) => Project::withTrashed()->findOrFail($attributes['project_id'])->scope_id,
            'created_by' => fn (array $attributes) => Scope::findOrFail($attributes['scope_id'])->owner_id,
            'creator_name' => 'Создатель счёта', 'title' => fake()->words(2, true), 'currency' => 'RUB', 'opening_minor' => '0',
        ];
    }
}
