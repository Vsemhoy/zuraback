<?php

namespace Database\Factories;

use App\Models\BookSpace;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookSpace>
 */
class BookSpaceFactory extends Factory
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
            'title' => fake()->words(2, true),
            'visibility' => 'private',
        ];
    }
}
