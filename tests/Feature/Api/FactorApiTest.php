<?php

namespace Tests\Feature\Api;

use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FactorApiTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax'];

    public function test_fact_can_be_created_and_updated_inside_scope(): void
    {
        $user = User::factory()->create();
        $scope = Scope::query()->create(['owner_id' => $user->id, 'name' => 'Work', 'slug' => 'work']);
        $scope->members()->create(['user_id' => $user->id, 'role' => 'owner', 'joined_at' => now()]);

        $fact = $this->actingAs($user)->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/facts", [
            'label' => 'Server address', 'value' => 'srv-1c.local', 'format' => 'code', 'kind' => 'configuration', 'is_sensitive' => true,
        ])->assertCreated()->assertJsonPath('data.is_sensitive', true)->json('data');

        $this->withHeaders(self::HEADERS)->patchJson("/api/scopes/{$scope->id}/facts/{$fact['id']}", [
            'value' => 'srv-1c-02.local', 'is_expert' => true, 'is_pinned' => true,
        ])->assertOk()->assertJsonPath('data.value', 'srv-1c-02.local')->assertJsonPath('data.is_expert', true)->assertJsonPath('data.is_pinned', true);
    }
}
