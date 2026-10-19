<?php

namespace Database\Factories;

use App\Models\Scope;
use App\Models\ThingType;
use Illuminate\Database\Eloquent\Factories\Factory;

class ThingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'scope_id' => Scope::factory(),
            'thing_type_id' => fn (array $attributes) => ThingType::factory()->create(['scope_id' => $attributes['scope_id']])->id,
            'name' => fake()->words(3, true),
            'registration_basis' => 'Первичная инвентаризация',
            'characteristics' => [],
        ];
    }
}
