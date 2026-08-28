<?php

namespace Tests\Feature\Api;

use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventorApiTest extends TestCase
{
    use RefreshDatabase;
    private const HEADERS = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax'];

    public function test_eventor_references_are_scoped_and_event_can_be_updated(): void
    {
        $user = User::factory()->create();
        $scope = Scope::query()->create(['owner_id' => $user->id, 'name' => 'Work', 'slug' => 'work']);
        $scope->members()->create(['user_id' => $user->id, 'role' => 'owner', 'joined_at' => now()]);
        $type = $this->actingAs($user)->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/event-types", ['name' => 'Meeting', 'color' => '#3975c6'])->assertCreated()->json('data');
        $section = $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/event-sections", ['name' => 'Operations'])->assertCreated()->json('data');
        $event = $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/events", ['title' => 'Access review', 'type_id' => $type['id'], 'section_id' => $section['id'], 'starts_at' => '2026-08-28 10:00:00'])->assertCreated()->json('data');
        $this->withHeaders(self::HEADERS)->patchJson("/api/scopes/{$scope->id}/events/{$event['id']}", ['status' => 'published', 'location' => 'Server room'])
            ->assertOk()->assertJsonPath('data.status', 'published')->assertJsonPath('data.location', 'Server room');
    }
}
