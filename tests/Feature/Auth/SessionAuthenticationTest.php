<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SessionAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax'];

    public function test_trusted_user_can_login_with_email_and_read_session(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        $this->withHeaders(self::HEADERS)
            ->postJson('/api/auth/login', ['identity' => $user->email, 'password' => 'secret-password'])
            ->assertOk()->assertJsonPath('data.email', $user->email);

        $this->assertAuthenticatedAs($user);
        $this->withHeaders(self::HEADERS)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_user_can_login_with_username_and_logout(): void
    {
        $user = User::factory()->create(['username' => 'boss', 'password' => 'secret-password']);

        $this->withHeaders(self::HEADERS)
            ->postJson('/api/auth/login', ['identity' => 'boss', 'password' => 'secret-password'])->assertOk();
        $this->withHeaders(self::HEADERS)->postJson('/api/auth/logout')->assertNoContent();
        $this->assertGuest();
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->withHeaders(self::HEADERS)
            ->postJson('/api/auth/login', ['identity' => $user->email, 'password' => 'wrong-password'])
            ->assertUnprocessable()->assertJsonValidationErrors('identity');
        $this->assertGuest();
    }

    public function test_disabled_user_cannot_login_or_keep_a_session(): void
    {
        $user = User::factory()->create(['password' => 'secret-password', 'is_active' => false]);
        $this->withHeaders(self::HEADERS)
            ->postJson('/api/auth/login', ['identity' => $user->email, 'password' => 'secret-password'])->assertUnprocessable();
        $this->actingAs($user)->withHeaders(self::HEADERS)->getJson('/api/auth/me')->assertUnauthorized();
        $this->assertGuest();
    }

    public function test_untrusted_requests_are_rejected(): void
    {
        $this->postJson('/api/auth/login', [])->assertForbidden();
        $this->withHeaders([...self::HEADERS, 'Origin' => 'https://evil.example'])
            ->postJson('/api/auth/login', [])->assertForbidden();
    }
}
