<?php

namespace Database\Factories;

use App\Models\ContentCategory;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContentCategory>
 */
class ContentCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope_id' => Scope::factory(), 'created_by' => User::factory(), 'name' => fake()->unique()->words(2, true),
        ];
    }
}
