<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Comment;
use App\Models\Darklog;
use App\Models\Department;
use App\Models\Event;
use App\Models\FilerFile;
use App\Models\Kpi;
use App\Models\LoreEntry;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DarklogApiTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax'];

    private function workspace(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15T12:00:00Z'));
        $owner = User::factory()->create(['is_executor' => true]);
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $this->actingAs($owner)->withHeaders(self::HEADERS);

        return [$owner, $scope, "/api/scopes/{$scope->id}/darklogs"];
    }

    private function item(User $owner, Scope $scope, array $attributes = []): Darklog
    {
        $item = Darklog::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, ...$attributes]);
        $item->periods()->create(['starts_at' => '2026-10-01 00:00:00']);

        return $item;
    }

    public function test_responsible_options_only_include_active_real_and_virtual_people(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $human = User::factory()->create(['type' => 'real', 'is_executor' => false]);
        $virtual = User::factory()->create(['type' => 'virtual', 'is_executor' => false]);
        $agent = User::factory()->create(['type' => 'agent']);
        foreach ([$human, $virtual, $agent] as $person) {
            $scope->members()->create(['user_id' => $person->id, 'role' => 'member', 'project_access_mode' => 'all']);
        }
        $people = $this->getJson($url.'/options')->assertOk()->assertJsonCount(3, 'data.people')->json('data.people');
        $this->assertEqualsCanonicalizing([$owner->id, $human->id, $virtual->id], array_column($people, 'id'));
        $item = $this->item($owner, $scope);
        $this->patchJson($url.'/'.$item->id, ['responsible_id' => $virtual->id])->assertOk()->assertJsonPath('data.responsible.id', $virtual->id);
        $this->assertDatabaseHas('darklogs', ['id' => $item->id, 'responsible_id' => $virtual->id]);
    }

    public function test_assigning_an_agent_is_rejected_on_create_update_and_legacy_reassignment(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $agent = User::factory()->create(['type' => 'agent']);
        $scope->members()->create(['user_id' => $agent->id, 'role' => 'member', 'project_access_mode' => 'all']);
        $this->postJson($url, ['title' => 'Wrong maintainer', 'responsible_id' => $agent->id, 'periods' => [['starts_at' => '2026-10-10T12:00:00Z']]])
            ->assertUnprocessable()->assertJsonValidationErrors('responsible_id')
            ->assertJsonPath('errors.responsible_id.0', 'Ответственным может быть только реальный или виртуальный человек, не агент.');
        $this->assertDatabaseCount('darklogs', 0);
        $item = $this->item($owner, $scope, ['responsible_id' => $owner->id]);
        $this->patchJson($url.'/'.$item->id, ['responsible_id' => $agent->id])->assertUnprocessable()->assertJsonValidationErrors('responsible_id');
        $this->assertDatabaseHas('darklogs', ['id' => $item->id, 'responsible_id' => $owner->id]);
        $item->update(['responsible_id' => $agent->id]);
        $this->patchJson($url.'/'.$item->id, ['title' => 'Historical record remains editable'])->assertOk();
        $this->patchJson($url.'/'.$item->id, ['responsible_id' => $agent->id])->assertUnprocessable()->assertJsonValidationErrors('responsible_id');
        $this->patchJson($url.'/'.$item->id, ['responsible_id' => null])->assertOk()->assertJsonPath('data.responsible', null);
        $this->assertDatabaseHas('darklogs', ['id' => $item->id, 'responsible_id' => null]);
    }

    public function test_problem_without_a_culprit_is_created_with_periods_and_audited(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $id = $this->postJson($url, ['title' => 'No internet', 'origin' => 'external', 'kind' => 'external_factor',
            'description' => '# Outage', 'periods' => [['starts_at' => '2026-10-01T09:00:00Z', 'ends_at' => null]]])
            ->assertCreated()->assertJsonPath('data.code', 'DRK-1')->assertJsonPath('data.responsible', null)
            ->assertJsonPath('data.origin', 'external')->json('data.id');
        $this->assertDatabaseHas('darklogs', ['id' => $id, 'scope_id' => $scope->id, 'created_by' => $owner->id]);
        $this->assertDatabaseHas('darklog_periods', ['darklog_id' => $id, 'ends_at' => null]);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $id, 'action' => 'darklog.created']);
    }

    public function test_overlapping_periods_are_rejected_atomically_and_touching_periods_are_allowed(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $payload = ['title' => 'Overlap', 'periods' => [
            ['starts_at' => '2026-10-01T09:00:00Z', 'ends_at' => '2026-10-03T09:00:00Z'],
            ['starts_at' => '2026-10-02T09:00:00Z', 'ends_at' => null],
        ]];
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('periods');
        $this->assertDatabaseCount('darklogs', 0);
        $payload['periods'][1]['starts_at'] = '2026-10-03T09:00:00Z';
        $this->postJson($url, $payload)->assertCreated()->assertJsonCount(2, 'data.periods');
    }

    public function test_closed_status_requires_ended_influence_and_accepted_status_requires_reason(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $item = $this->item($owner, $scope);
        $this->patchJson($url.'/'.$item->id, ['status' => 'closed'])->assertUnprocessable()->assertJsonValidationErrors('periods');
        $this->patchJson($url.'/'.$item->id, ['status' => 'accepted'])->assertUnprocessable()->assertJsonValidationErrors('decision_reason');
        $this->patchJson($url.'/'.$item->id, ['status' => 'accepted', 'decision_reason' => 'Approved temporary restriction'])
            ->assertOk()->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.periods.0.ends_at', null);
        $period = $item->periods()->first();
        $this->patchJson($url.'/'.$item->id, ['status' => 'closed', 'periods' => [['id' => $period->id, 'starts_at' => $period->starts_at->toISOString(), 'ends_at' => '2026-10-15T10:00:00Z']]])
            ->assertOk()->assertJsonPath('data.status', 'closed');
    }

    public function test_month_overlap_uses_half_open_intervals_and_timezone(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $before = $this->item($owner, $scope, ['title' => 'Ended before October']);
        $before->periods()->update(['starts_at' => '2026-09-01 00:00:00', 'ends_at' => '2026-09-30 21:00:00']);
        $active = $this->item($owner, $scope, ['title' => 'Ongoing']);
        $active->periods()->update(['starts_at' => '2026-09-01 00:00:00']);
        $this->getJson($url.'?month=2026-10&timezone=Europe/Moscow')->assertOk()->assertJsonPath('data.total', 1)->assertJsonPath('data.items.0.id', $active->id)
            ->assertJsonMissing(['id' => $before->id]);
    }

    public function test_scope_and_private_project_boundaries_protect_every_surface(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private']);
        $secret = $this->item($owner, $scope, ['project_id' => $project->id, 'title' => 'Secret']);
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'project_access_mode' => 'all']);
        $this->actingAs($member)->getJson($url.'?month=2026-10')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson($url.'/'.$secret->id)->assertNotFound();
        $this->patchJson($url.'/'.$secret->id, ['title' => 'Overwrite'])->assertNotFound();
        $this->postJson($url.'/'.$secret->id.'/comments', ['content' => 'Leak'])->assertNotFound();
        $foreign = Scope::factory()->create(['owner_id' => $owner->id]);
        $item = $this->item($owner, $foreign);
        $this->actingAs($owner)->getJson($url.'/'.$item->id)->assertNotFound();
    }

    public function test_foreign_department_responsible_and_period_ids_are_rejected(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $item = $this->item($owner, $scope);
        $foreign = Department::factory()->create();
        $outsider = User::factory()->create();
        $this->patchJson($url.'/'.$item->id, ['department_id' => $foreign->id])->assertUnprocessable()->assertJsonValidationErrors('department_id');
        $this->patchJson($url.'/'.$item->id, ['responsible_id' => $outsider->id])->assertUnprocessable()->assertJsonValidationErrors('responsible_id');
        $other = $this->item($owner, $scope);
        $this->patchJson($url.'/'.$item->id, ['periods' => [['id' => $other->periods()->first()->id, 'starts_at' => '2026-10-01T00:00:00Z', 'ends_at' => null]]])->assertUnprocessable();
        $this->assertDatabaseHas('darklogs', ['id' => $item->id, 'department_id' => null, 'responsible_id' => null]);
    }

    public function test_links_use_actual_record_time_not_the_link_creation_date_and_hide_private_targets(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $item = $this->item($owner, $scope);
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'status' => 'done', 'completed_at' => '2026-10-04 10:00:00']);
        $this->postJson($url.'/'.$item->id.'/links', ['target_type' => 'task', 'target_id' => $task->id, 'relation' => 'confirmed_cause'])
            ->assertOk()->assertJsonPath('data.markers.0.at', '2026-10-04T10:00:00.000000Z');
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private']);
        $private = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'project_id' => $project->id, 'title' => 'Hidden title']);
        $this->postJson($url.'/'.$item->id.'/links', ['target_type' => 'task', 'target_id' => $private->id, 'relation' => 'context'])->assertOk();
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'project_access_mode' => 'all']);
        $this->actingAs($member)->getJson($url.'/'.$item->id)->assertOk()->assertJsonCount(1, 'data.links')->assertJsonMissing(['title' => 'Hidden title']);
        $this->getJson($url.'/candidates?type=task&q=Hidden')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($url.'/'.$item->id.'/links', ['target_type' => 'task', 'target_id' => $private->id, 'relation' => 'affected'])->assertNotFound();
    }

    public function test_comment_replies_have_one_level_and_only_author_or_manager_can_delete(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $item = $this->item($owner, $scope);
        $this->postJson($url.'/'.$item->id.'/comments', ['content' => 'Root'])->assertOk();
        $root = Comment::query()->firstOrFail();
        $this->postJson($url.'/'.$item->id.'/comments', ['content' => 'Reply', 'parent_id' => $root->id])->assertOk();
        $reply = Comment::query()->where('parent_id', $root->id)->firstOrFail();
        $this->postJson($url.'/'.$item->id.'/comments', ['content' => 'Too deep', 'parent_id' => $reply->id])->assertUnprocessable();
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'project_access_mode' => 'all']);
        $this->actingAs($member)->deleteJson($url.'/'.$item->id.'/comments/'.$root->id)->assertForbidden();
        $this->actingAs($owner)->deleteJson($url.'/'.$item->id.'/comments/'.$root->id)->assertNoContent();
        $this->assertSoftDeleted($root);
        $this->assertModelExists($reply);
    }

    public function test_kpi_applicability_is_separate_from_responsibility_and_requires_manager_review(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $item = $this->item($owner, $scope);
        $kpi = Kpi::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $member = User::factory()->create(['is_executor' => true]);
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'project_access_mode' => 'all']);
        $payload = ['user_id' => $owner->id, 'kpi_id' => $kpi->id, 'decision' => 'included', 'reason' => 'Confirmed scope', 'effective_from' => '2026-10-01T00:00:00Z'];
        $this->actingAs($member)->postJson($url.'/'.$item->id.'/kpi', $payload)->assertForbidden();
        $this->postJson($url.'/'.$item->id.'/kpi', [...$payload, 'decision' => 'pending'])->assertOk()->assertJsonPath('data.kpi_links.0.decision', 'pending');
        $this->actingAs($owner)->postJson($url.'/'.$item->id.'/kpi', $payload)->assertOk()
            ->assertJsonPath('data.kpi_links.0.reviewed_by', $owner->id)->assertJsonPath('data.responsible', null);
        $this->actingAs($member)->postJson($url.'/'.$item->id.'/kpi', [...$payload, 'decision' => 'pending'])->assertForbidden();
        $this->assertDatabaseHas('darklog_kpi_links', ['darklog_id' => $item->id, 'decision' => 'included']);
    }

    public function test_observers_and_read_only_agents_cannot_write_and_deleted_records_keep_history(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $item = $this->item($owner, $scope);
        $observer = User::factory()->create();
        $scope->members()->create(['user_id' => $observer->id, 'role' => 'observer', 'project_access_mode' => 'all']);
        $this->actingAs($observer)->getJson($url.'/'.$item->id)->assertOk();
        $this->patchJson($url.'/'.$item->id, ['title' => 'Denied'])->assertForbidden();
        $this->actingAs($owner)->deleteJson($url.'/'.$item->id)->assertNoContent();
        $this->assertSoftDeleted($item);
        $this->assertDatabaseCount('darklog_periods', 1);
        $this->getJson($url.'/'.$item->id)->assertNotFound();
    }

    public function test_subject_backreferences_include_old_records_but_do_not_reveal_hidden_targets(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $item = $this->item($owner, $scope);
        $item->periods()->update(['starts_at' => '2026-09-01 00:00:00', 'ends_at' => '2026-09-02 00:00:00']);
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $item->links()->create(['target_type' => 'task', 'target_id' => $task->id, 'relation' => 'affected', 'created_by' => $owner->id]);
        $this->getJson($url.'?month=2026-10')->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson($url.'?month=2026-10&subject_type=task&subject_id='.$task->id)->assertOk()->assertJsonPath('data.total', 1);
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private']);
        $task->update(['project_id' => $project->id]);
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'project_access_mode' => 'all']);
        $this->actingAs($member)->getJson($url.'?month=2026-10&subject_type=task&subject_id='.$task->id)->assertNotFound();
    }

    public function test_dates_with_offsets_are_normalized_and_partial_updates_preserve_content(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $item = $this->item($owner, $scope, ['description' => '# Keep this']);
        $this->patchJson($url.'/'.$item->id, ['expected_end_at' => '2026-10-20T12:00:00+03:00'])->assertOk()
            ->assertJsonPath('data.description', '# Keep this')->assertJsonPath('data.expected_end_at', '2026-10-20T09:00:00.000000Z')->assertJsonCount(1, 'data.periods');
        $kpi = Kpi::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $this->postJson($url.'/'.$item->id.'/kpi', ['user_id' => $owner->id, 'kpi_id' => $kpi->id, 'decision' => 'pending', 'effective_from' => '2026-10-01T12:00:00+03:00'])
            ->assertOk()->assertJsonPath('data.kpi_links.0.effective_from', '2026-10-01T09:00:00.000000Z');
        $this->patchJson($url.'/'.$item->id, ['periods' => [['starts_at' => '2026-10-20T12:00:00Z']]])->assertUnprocessable();
        $this->patchJson($url.'/'.$item->id, ['periods' => [['starts_at' => '2026-10-01T12:00:00.1Z', 'ends_at' => '2026-10-01T12:00:00.2Z']]])->assertUnprocessable();
    }

    public function test_private_books_events_and_lore_are_not_exposed_through_links(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $item = $this->item($owner, $scope);
        $book = Book::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private']);
        $event = Event::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private']);
        $lore = LoreEntry::query()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private', 'code' => 'PRIVATE-LORE']);
        foreach (['book' => $book, 'event' => $event, 'lore' => $lore] as $type => $target) {
            $item->links()->create(['target_type' => $type, 'target_id' => $target->id, 'relation' => 'context', 'created_by' => $owner->id]);
        }
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'project_access_mode' => 'all']);
        $this->actingAs($member)->getJson($url.'/'.$item->id)->assertOk()->assertJsonCount(0, 'data.links')->assertJsonCount(0, 'data.markers');
        foreach (['book', 'event', 'lore'] as $type) {
            $this->getJson($url.'/candidates?type='.$type)->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_darklog_attachments_and_comment_markers_follow_project_access(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        Storage::fake('filer');
        config(['filer.reserve_bytes' => 0]);
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private']);
        $item = $this->item($owner, $scope, ['project_id' => $project->id]);
        $this->postJson($url.'/'.$item->id.'/comments', ['content' => 'A useful update'])->assertOk()
            ->assertJsonPath('data.markers.0.module', 'comment')->assertJsonPath('data.markers.0.title', 'A useful update');
        $fileId = $this->withHeader('Content-Type', 'multipart/form-data; boundary=test')->post("/api/scopes/{$scope->id}/files", [
            'file' => UploadedFile::fake()->createWithContent('notes.txt', 'private investigation'),
            'category' => 'darklog', 'visibility' => 'scope', 'subject_type' => 'darklog', 'subject_id' => $item->id,
        ])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('filer_attachments', ['subject_type' => 'darklog', 'subject_id' => $item->id]);
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'project_access_mode' => 'all']);
        $this->actingAs($member)->getJson("/api/scopes/{$scope->id}/files/{$fileId}/download")->assertNotFound();
        $this->assertModelExists(FilerFile::findOrFail($fileId));
    }

    public function test_anonymous_requests_and_department_deletion_are_rejected(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $department = Department::factory()->create(['scope_id' => $scope->id]);
        $this->item($owner, $scope, ['department_id' => $department->id]);
        $this->deleteJson("/api/scopes/{$scope->id}/departments/{$department->id}")->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->getJson($url.'?month=2026-10')->assertUnauthorized();
    }

    public function test_archived_projects_do_not_hide_history_or_relax_private_access(): void
    {
        [$owner, $scope, $url] = $this->workspace();
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private']);
        $item = $this->item($owner, $scope, ['project_id' => $project->id, 'description' => 'Private notes']);
        $project->delete();
        $this->getJson($url.'?month=2026-10')->assertOk()->assertJsonPath('data.total', 1)->assertJsonMissing(['description' => 'Private notes']);
        $this->patchJson($url.'/'.$item->id, ['title' => 'Continue', 'project_id' => $project->id])->assertOk()->assertJsonPath('data.description', 'Private notes');
        $this->postJson($url, ['title' => 'New in archive', 'project_id' => $project->id, 'periods' => [['starts_at' => '2026-10-10T12:00:00Z']]])->assertUnprocessable();
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'project_access_mode' => 'all']);
        $this->actingAs($member)->getJson($url.'/'.$item->id)->assertNotFound();
    }

    public function test_agent_api_respects_token_abilities_and_scope(): void
    {
        [$owner, $scope] = $this->workspace();
        $item = $this->item($owner, $scope);
        $agent = User::factory()->create(['type' => 'agent']);
        $scope->members()->create(['user_id' => $agent->id, 'role' => 'member', 'project_access_mode' => 'all']);
        $token = $agent->createToken('Darklog reader', ['task.view'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson("/api/agent/scopes/{$scope->id}/darklogs/{$item->id}")->assertOk();
        $this->withToken($token)->patchJson("/api/agent/scopes/{$scope->id}/darklogs/{$item->id}", ['title' => 'Denied'])->assertForbidden();
    }

    public function test_agent_can_create_edit_link_comment_and_read_current_spec(): void
    {
        [$owner, $scope] = $this->workspace();
        $agent = User::factory()->create(['type' => 'agent']);
        $scope->members()->create(['user_id' => $agent->id, 'role' => 'member', 'project_access_mode' => 'all']);
        $token = $agent->createToken('Darklog worker', ['task.view', 'task.create', 'task.update'])->plainTextToken;
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'status' => 'done']);
        $this->app['auth']->forgetGuards();
        $this->withToken($token);
        $url = "/api/agent/scopes/{$scope->id}/darklogs";
        $id = $this->postJson($url, ['title' => 'Agent investigation', 'description' => 'Keep notes', 'periods' => [['starts_at' => '2026-10-10T12:00:00Z']]])
            ->assertCreated()->assertJsonPath('data.created_by', $agent->id)->json('data.id');
        $this->patchJson($url.'/'.$id, ['status' => 'investigating'])->assertOk()->assertJsonPath('data.description', 'Keep notes');
        $this->postJson($url.'/'.$id.'/links', ['target_type' => 'task', 'target_id' => $task->id, 'relation' => 'possible_cause'])->assertOk();
        $this->postJson($url.'/'.$id.'/comments', ['content' => 'Checking the cause'])->assertOk()->assertJsonFragment(['content' => 'Checking the cause']);
        $this->get('/api/agent/spec', ['Accept' => 'text/markdown'])->assertOk()->assertSee('## Darklog:', false)->assertSee('NO automatic point deduction/award', false)->assertDontSee($token, false);
        $this->deleteJson($url.'/'.$id)->assertForbidden();
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $id, 'actor_id' => $agent->id, 'action' => 'darklog.updated']);
    }
}
