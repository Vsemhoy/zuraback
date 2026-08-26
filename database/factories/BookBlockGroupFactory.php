<?php

namespace Database\Factories;

use App\Models\BookBlockGroup;
use App\Models\BookPage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookBlockGroup>
 */
class BookBlockGroupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'page_id' => BookPage::factory(),
            'created_by' => User::factory(),
            'type' => fake()->randomElement(['markdown', 'excalidraw', 'svg', 'table', 'code', 'callout', 'checklist', 'divider', 'embed']),
            'role' => 'content',
        ];
    }
}
