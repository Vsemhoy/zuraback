<?php

namespace Database\Factories;

use App\Models\CompensationPeriod;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CompensationPeriod>
 */
class CompensationPeriodFactory extends Factory
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
            'month' => now()->startOfMonth(),
            'salary_amount' => 100000,
            'status' => 'open',
        ];
    }
}
