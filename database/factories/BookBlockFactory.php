<?php

namespace Database\Factories;

use App\Models\BookBlock;
use App\Models\BookBlockGroup;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookBlock>
 */
class BookBlockFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => BookBlockGroup::factory(),
            'created_by' => User::factory(),
            'version_number' => 1,
            'content' => fake()->paragraph(),
            'status' => 'draft',
        ];
    }
}
