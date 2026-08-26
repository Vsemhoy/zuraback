<?php

namespace Database\Factories;

use App\Models\Book;
use App\Models\BookPage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookPage>
 */
class BookPageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'book_id' => Book::factory(),
            'created_by' => User::factory(),
            'title' => fake()->sentence(3),
            'visibility' => 'private',
        ];
    }
}
