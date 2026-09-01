<?php

namespace Database\Factories;

use App\Models\ContractorDelegation;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContractorDelegation>
 */
class ContractorDelegationFactory extends Factory
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
            'operator_id' => User::factory(),
            'contractor_id' => User::factory()->virtual(),
            'assigned_by' => User::factory(),
            'permissions' => [],
            'is_active' => true,
        ];
    }
}
