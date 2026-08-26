<?php

namespace Database\Factories;

use App\Models\Fact;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Fact>
 */
class FactFactory extends Factory
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
            'label' => fake()->words(2, true),
            'value' => fake()->sentence(),
            'format' => 'text',
            'kind' => 'other',
        ];
    }
}
