<?php

namespace Database\Factories;

use App\Models\Scope;
use App\Models\ScopeMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ScopeMember>
 */
class ScopeMemberFactory extends Factory
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
            'user_id' => User::factory(),
            'role' => 'member',
            'is_active' => true,
            'joined_at' => now(),
        ];
    }
}
