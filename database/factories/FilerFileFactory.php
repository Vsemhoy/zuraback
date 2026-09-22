<?php

namespace Database\Factories;

use App\Models\Scope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class FilerFileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'scope_id' => Scope::factory(), 'created_by' => User::factory(), 'uploaded_by' => User::factory(),
            'name' => 'sample.txt', 'category' => 'general', 'visibility' => 'private',
            'disk' => 'filer', 'path' => 'test/'.Str::ulid().'/original', 'mime' => 'text/plain',
            'size' => 4, 'sha256' => hash('sha256', 'test'),
        ];
    }
}
