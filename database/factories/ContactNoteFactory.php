<?php

namespace Database\Factories;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactNoteFactory extends Factory
{
    public function definition(): array
    {
        return ['contact_id' => Contact::factory(), 'created_by' => User::factory(), 'content' => fake()->paragraph(), 'occurred_at' => now()];
    }
}
