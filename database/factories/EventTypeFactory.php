<?php

namespace Database\Factories;

use App\Models\EventType;
use App\Models\Scope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EventType>
 */
class EventTypeFactory extends Factory
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
            'name' => fake()->words(2, true),
        ];
    }
}
