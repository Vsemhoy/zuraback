<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class StarterSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $email = config('starter.user.email');
        $password = config('starter.user.password');

        if (! is_string($email) || $email === '' || ! is_string($password) || $password === '') {
            throw new RuntimeException('Set STARTER_USER_EMAIL and STARTER_USER_PASSWORD before running StarterSeeder.');
        }

        User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => config('starter.user.name'),
                'username' => config('starter.user.username'),
                'password' => $password,
                'email_verified_at' => now(),
                'is_active' => true,
            ],
        );
    }
}
