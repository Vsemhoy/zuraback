<?php

namespace Database\Factories;

use App\Models\Scope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContactFactory extends Factory
{
    public function definition(): array
    {
        return ['scope_id' => Scope::factory(), 'created_by' => User::factory(), 'name' => fake()->name(), 'visibility' => 'private'];
    }
}
