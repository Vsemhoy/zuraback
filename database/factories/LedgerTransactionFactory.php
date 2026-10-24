<?php

namespace Database\Factories;

use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\Scope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LedgerTransaction>
 */
class LedgerTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => LedgerAccount::factory(), 'scope_id' => fn (array $attributes) => LedgerAccount::findOrFail($attributes['account_id'])->scope_id,
            'currency' => fn (array $attributes) => LedgerAccount::findOrFail($attributes['account_id'])->currency,
            'created_by' => fn (array $attributes) => Scope::findOrFail($attributes['scope_id'])->owner_id,
            'creator_name' => 'Автор операции', 'kind' => 'expense', 'amount_minor' => '100', 'occurred_on' => '2026-10-23',
            'status' => 'planned', 'title' => fake()->words(3, true), 'idempotency_key' => fake()->uuid(), 'request_hash' => str_repeat('0', 64),
        ];
    }
}
