<?php

namespace Tests\Feature\Api;

use App\Models\Scope;
use App\Models\Project;
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

    public function test_project_eventor_supports_filters_privacy_comments_and_diagrams(): void
    {
        $owner = User::factory()->create();
        $requester = User::factory()->create();
        $scope = Scope::query()->create(['owner_id' => $owner->id, 'name' => 'Work', 'slug' => 'work']);
        $scope->members()->create(['user_id' => $owner->id, 'role' => 'owner', 'joined_at' => now()]);
        $scope->members()->create(['user_id' => $requester->id, 'role' => 'member', 'joined_at' => now()]);
        $project = Project::query()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'title' => 'CRM', 'key' => 'CRM', 'show_in_eventor' => true]);

        $types = $this->actingAs($owner)->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/event-types")
            ->assertOk()->json('data');
        $requestType = collect($types)->firstWhere('code', 'request');
        $event = $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/events", [
            'title' => 'Sales reported an outage', 'content' => 'Cannot create an invoice', 'project_id' => $project->id,
            'type_id' => $requestType['id'], 'requester_id' => $requester->id, 'importance' => 'incident',
            'visibility' => 'private', 'is_pinned' => true, 'is_blurred' => true, 'comments_enabled' => true,
            'starts_at' => '2026-09-02 12:00:00',
        ])->assertCreated()->assertJsonPath('data.project.id', $project->id)->assertJsonPath('data.requester.id', $requester->id)
            ->assertJsonPath('data.importance', 'incident')->assertJsonPath('data.comments_allowed', true)->json('data');

        $this->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/events?project_id={$project->id}&importance=incident&q=invoice")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $event['id']);
        $this->withHeaders(self::HEADERS)->postJson("/api/scopes/{$scope->id}/events/{$event['id']}/comments", ['content' => 'Investigating'])
            ->assertCreated()->assertJsonPath('data.content', 'Investigating');
        $this->withHeaders(self::HEADERS)->patchJson("/api/scopes/{$scope->id}/events/{$event['id']}", [
            'starts_at' => '2026-09-03 09:30:00', 'diagram' => ['elements' => [], 'files' => [], 'svg' => '<svg/>'],
            'attachments' => [['url' => 'https://example.com/spec.pdf', 'label' => 'Specification']],
            'photos' => [['url' => 'https://example.com/photo.jpg', 'label' => 'Incidentpolis']],
        ])->assertOk()->assertJsonPath('data.diagram.svg', '<svg/>')->assertJsonPath('data.attachments.0.label', 'Specification')
            ->assertJsonPath('data.photos.0.label', 'Incidentpolis');
        $this->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/events/{$event['id']}/comments")
            ->assertOk()->assertJsonCount(1, 'data');

        $this->withHeaders(self::HEADERS)->patchJson("/api/scopes/{$scope->id}/projects/{$project->id}", [
            'show_in_tasker' => false, 'show_in_eventor' => true, 'event_comments_enabled' => false,
        ])->assertOk()->assertJsonPath('data.show_in_tasker', false)->assertJsonPath('data.event_comments_enabled', false);
    }
}
