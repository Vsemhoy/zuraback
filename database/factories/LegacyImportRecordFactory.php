<?php

namespace Database\Factories;

use App\Models\LegacyImportRecord;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<LegacyImportRecord>
 */
class LegacyImportRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope_id' => Scope::factory(), 'created_by' => User::factory(),
            'batch_id' => (string) Str::ulid(), 'source' => 'home.zuratax.com',
            'source_type' => 'fact', 'source_id' => (string) Str::ulid(),
            'target_type' => 'fact', 'target_id' => (string) Str::ulid(),
            'source_hash' => hash('sha256', 'factory'), 'snapshot' => ['value' => 'fixture'],
        ];
    }
}
