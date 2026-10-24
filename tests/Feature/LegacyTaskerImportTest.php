<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\LegacyImportRecord;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\User;
use App\Services\LegacyTaskerImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class LegacyTaskerImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id, 'is_private' => true]);
        $oldOwner = (string) Str::ulid();
        $row = fn (array $attrs): array => ['id' => Str::lower((string) Str::ulid()), 'user_id' => $oldOwner,
            'created_at' => '2025-03-01 10:00:00', 'updated_at' => '2025-03-02 11:00:00', 'deleted_at' => null, 'sort_order' => 0, ...$attrs];
        $project = $row(['title' => 'Личный проект', 'code' => 'LEG', 'color' => '#5588aa', 'status_id' => 21, 'priority_id' => 13, 'description' => '# Проект']);
        $parent = $row(['project_id' => $project['id'], 'title' => 'Родитель', 'description' => "# Описание\n```js\nx()\n```\nХвост", 'result' => 'Результат', 'status_id' => 22, 'priority_id' => 11, 'tracked_seconds' => 120]);
        $child = $row(['project_id' => $project['id'], 'parent_task_id' => $parent['id'], 'title' => 'Дочерняя', 'status_id' => 20, 'priority_id' => 15, 'tracked_seconds' => 0]);
        $snapshot = ['schema' => 1, 'source' => 'home.zuratax.com', 'owner_id' => $oldOwner, 'owner_hash' => hash('sha256', Str::lower($owner->email)),
            'projects' => [$project], 'tasks' => [$parent, $child],
            'checklist_items' => [$row(['task_id' => $parent['id'], 'title' => 'Пункт', 'status_id' => 22])],
            'logs' => [$row(['task_id' => $parent['id'], 'kind' => 'note', 'content' => 'Комментарий']), $row(['task_id' => $parent['id'], 'kind' => 'status_change', 'content' => '21 -> 22', 'meta' => '{"from":21,"to":22}'])],
            'spans' => [$row(['task_id' => $parent['id'], 'kind' => 'fact', 'started_at' => '2025-03-01 09:00:00', 'ended_at' => '2025-03-01 09:02:00'])], 'blockers' => []];

        return [$scope, $owner, $snapshot];
    }

    public function test_dry_run_does_not_write_records_or_consume_task_numbers(): void
    {
        [$scope, , $snapshot] = $this->fixture();

        $plan = app(LegacyTaskerImportService::class)->import($scope, $snapshot);

        $this->assertSame(6, $plan['planned']);
        $this->assertSame(0, $plan['created']);
        foreach (['projects', 'tasks', 'task_checklist_items', 'comments', 'activity_logs', 'legacy_import_records'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_import_preserves_content_hierarchy_history_checklists_and_legacy_spans(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();

        $result = app(LegacyTaskerImportService::class)->import($scope, $snapshot, true);

        $parent = Task::findOrFail($result['task_map'][$snapshot['tasks'][0]['id']]);
        $child = Task::findOrFail($result['task_map'][$snapshot['tasks'][1]['id']]);
        $this->assertNotSame($snapshot['tasks'][0]['id'], $parent->id);
        $this->assertSame($snapshot['tasks'][0]['description'], $parent->description);
        $this->assertSame('Результат', $parent->result);
        $this->assertSame('done', $parent->status);
        $this->assertSame(5, $parent->priority);
        $this->assertSame('scheduled', $child->status);
        $this->assertSame(1, $child->priority);
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame('LEG-1', $parent->task_key);
        $this->assertSame('LEG-2', $child->task_key);
        $this->assertCount(1, $parent->meta['legacy_spans']);
        $this->assertDatabaseHas('projects', ['id' => $parent->project_id, 'visibility' => 'private', 'include_in_reports' => false]);
        $this->assertDatabaseHas('task_checklist_items', ['task_id' => $parent->id, 'title' => 'Пункт', 'completed_by' => $owner->id, 'completed_at' => '2025-03-02 11:00:00']);
        $this->assertDatabaseHas('comments', ['commentable_id' => $parent->id, 'content' => 'Комментарий']);
        $activity = ActivityLog::where('subject_id', $parent->id)->sole();
        $this->assertSame(['status' => 'in_progress'], $activity->before);
        $this->assertSame(['status' => 'done'], $activity->after);
        $this->assertDatabaseCount('legacy_import_records', 6);
    }

    public function test_rerun_is_idempotent_preserves_user_edits_and_does_not_resurrect_deleted_tasks(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $importer = app(LegacyTaskerImportService::class);
        $result = $importer->import($scope, $snapshot, true);
        $childId = $result['task_map'][$snapshot['tasks'][1]['id']];
        Task::findOrFail($childId)->update(['description' => 'Edited locally']);
        Task::findOrFail($childId)->delete();

        $again = $importer->import($scope, $snapshot, true);

        $this->assertSame(0, $again['created']);
        $this->assertSame(6, array_sum($again['counts']['skip']));
        $this->assertDatabaseCount('legacy_import_records', 6);
        $this->assertDatabaseHas('tasks', ['id' => $childId, 'description' => 'Edited locally']);
        $this->assertSoftDeleted('tasks', ['id' => $childId]);
        $this->assertSame(3, Project::sole()->next_task_number);
    }

    public function test_import_does_not_overwrite_colliding_original_ids_in_other_scopes(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $otherTask = Task::factory()->create(['id' => $snapshot['tasks'][0]['id'], 'title' => 'Чужая задача']);
        $otherItem = TaskChecklistItem::forceCreate(['id' => $snapshot['checklist_items'][0]['id'], 'task_id' => $otherTask->id, 'title' => 'Чужой пункт', 'created_by' => $otherTask->created_by, 'sort_order' => 0]);

        $result = app(LegacyTaskerImportService::class)->import($scope, $snapshot, true);

        $this->assertDatabaseHas('tasks', ['id' => $otherTask->id, 'title' => 'Чужая задача']);
        $this->assertDatabaseHas('task_checklist_items', ['id' => $otherItem->id, 'task_id' => $otherTask->id, 'title' => 'Чужой пункт']);
        $this->assertNotSame($otherTask->id, $result['task_map'][$snapshot['tasks'][0]['id']]);
        $this->assertSame(2, $scope->tasks()->count());
    }

    public function test_reuses_proven_existing_project_and_task_without_overwriting_fields(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'key' => 'LEG', 'title' => 'Личный проект', 'description' => 'Newer description', 'next_task_number' => 8]);
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'project_id' => $project->id, 'title' => 'Родитель', 'description' => 'Newer task description', 'task_key' => 'LEG-7', 'number' => 7, 'created_at' => '2025-03-01 10:00:00']);

        $result = app(LegacyTaskerImportService::class)->import($scope, $snapshot, true);

        $this->assertSame($project->id, $result['project_map'][$snapshot['projects'][0]['id']]);
        $this->assertSame($task->id, $result['task_map'][$snapshot['tasks'][0]['id']]);
        $this->assertDatabaseCount('projects', 1);
        $this->assertDatabaseCount('tasks', 2);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'description' => 'Newer task description']);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'description' => 'Newer description']);
    }

    public function test_ambiguous_title_matches_abort_instead_of_creating_a_duplicate(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'key' => 'LEG', 'title' => 'Личный проект']);
        Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'project_id' => $project->id, 'title' => 'Родитель', 'created_at' => '2026-01-01 00:00:00']);

        try {
            app(LegacyTaskerImportService::class)->import($scope, $snapshot, true);
            $this->fail('Uncertain duplicate must require review.');
        } catch (LogicException) {
            $this->assertDatabaseCount('tasks', 1);
            $this->assertDatabaseCount('legacy_import_records', 0);
        }
    }

    public function test_cycles_and_missing_project_references_abort_without_partial_writes(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $snapshot['tasks'][0]['parent_task_id'] = $snapshot['tasks'][1]['id'];

        try {
            app(LegacyTaskerImportService::class)->import($scope, $snapshot, true);
            $this->fail('Cycles must be refused.');
        } catch (LogicException) {
            $this->assertDatabaseCount('projects', 0);
            $this->assertDatabaseCount('tasks', 0);
        }
        unset($snapshot['tasks'][0]['parent_task_id']);
        $snapshot['tasks'][0]['project_id'] = (string) Str::ulid();
        $this->expectException(LogicException::class);
        app(LegacyTaskerImportService::class)->import($scope, $snapshot, true);
    }

    public function test_contacts_become_links_not_new_users_or_automatic_account_assignments(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();
        $contact = Contact::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $sourceContact = Str::lower((string) Str::ulid());
        LegacyImportRecord::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'source_type' => 'contact', 'source_id' => $sourceContact, 'target_type' => 'contact', 'target_id' => $contact->id]);
        $snapshot['tasks'][0]['assignee_contact_id'] = $sourceContact;
        $users = User::count();

        $result = app(LegacyTaskerImportService::class)->import($scope, $snapshot, true);

        $taskId = $result['task_map'][$snapshot['tasks'][0]['id']];
        $this->assertDatabaseHas('entity_links', ['source_type' => 'task', 'source_id' => $taskId, 'target_type' => 'contact', 'target_id' => $contact->id, 'relation' => 'legacy_assignee']);
        $this->assertDatabaseHas('tasks', ['id' => $taskId, 'assignee_id' => null]);
        $this->assertSame($users, User::count());
    }

    public function test_archiving_failure_rolls_back_records_and_key_counters(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $event = 'eloquent.creating: '.LegacyImportRecord::class;
        Event::listen($event, function (): void {
            throw new RuntimeException('Synthetic failure');
        });

        try {
            app(LegacyTaskerImportService::class)->import($scope, $snapshot, true);
            $this->fail('Failure must abort.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('projects', 0);
            $this->assertDatabaseCount('tasks', 0);
            $this->assertDatabaseCount('legacy_import_records', 0);
        } finally {
            Event::forget($event);
        }
    }

    public function test_changed_source_is_not_silently_applied_to_an_existing_import(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $importer = app(LegacyTaskerImportService::class);
        $result = $importer->import($scope, $snapshot, true);
        $snapshot['tasks'][1]['description'] = 'New source content';

        try {
            $importer->import($scope, $snapshot, true);
            $this->fail('Changed source requires review.');
        } catch (LogicException) {
            $this->assertDatabaseHas('tasks', ['id' => $result['task_map'][$snapshot['tasks'][1]['id']], 'description' => null]);
            $this->assertDatabaseCount('legacy_import_records', 6);
        }
    }

    public function test_public_scope_and_mismatched_owner_are_refused(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $scope->update(['is_private' => false]);

        try {
            app(LegacyTaskerImportService::class)->import($scope, $snapshot, true);
            $this->fail('Public scope is not an acceptable personal-data destination.');
        } catch (LogicException) {
            $this->assertDatabaseCount('projects', 0);
        }
        $scope->update(['is_private' => true]);
        $snapshot['owner_hash'] = str_repeat('0', 64);
        $this->expectException(LogicException::class);
        app(LegacyTaskerImportService::class)->import($scope, $snapshot, true);
    }

    public function test_a_contact_reference_missing_from_the_target_aborts_without_creating_users(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $snapshot['tasks'][0]['assignee_contact_id'] = (string) Str::ulid();
        $users = User::count();

        try {
            app(LegacyTaskerImportService::class)->import($scope, $snapshot, true);
            $this->fail('Missing imported contact requires review.');
        } catch (LogicException) {
            $this->assertDatabaseCount('tasks', 0);
            $this->assertDatabaseCount('legacy_import_records', 0);
            $this->assertSame($users, User::count());
        }
    }

    public function test_a_previously_imported_contact_note_is_cross_linked_to_its_task(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();
        $contact = Contact::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $sourceContact = Str::lower((string) Str::ulid());
        LegacyImportRecord::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'source_type' => 'contact', 'source_id' => $sourceContact, 'target_type' => 'contact', 'target_id' => $contact->id]);
        LegacyImportRecord::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'source_type' => 'contact_note', 'target_type' => 'contact_note', 'snapshot' => ['tasker_task_id' => $snapshot['tasks'][0]['id'], 'contact_id' => $sourceContact, 'created_at' => '2025-03-01 10:00:00', 'updated_at' => '2025-03-02 11:00:00']]);

        $result = app(LegacyTaskerImportService::class)->import($scope, $snapshot, true);

        $this->assertDatabaseHas('entity_links', ['source_type' => 'contact', 'source_id' => $contact->id, 'target_type' => 'task', 'target_id' => $result['task_map'][$snapshot['tasks'][0]['id']], 'relation' => 'mentioned_in_note']);
    }

    public function test_cli_is_dry_by_default_and_requires_a_confirmed_snapshot_checksum(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $file = tempnam(sys_get_temp_dir(), 'legacy-tasker-test-');
        $bytes = json_encode($snapshot, JSON_THROW_ON_ERROR);
        file_put_contents($file, $bytes);
        chmod($file, 0600);

        try {
            $this->artisan('legacy:import-tasker', ['snapshot' => $file, '--scope' => $scope->id])->assertSuccessful();
            $this->assertDatabaseCount('tasks', 0);
            $this->artisan('legacy:import-tasker', ['snapshot' => $file, '--scope' => $scope->id, '--commit' => true])->assertFailed();
            $this->assertDatabaseCount('tasks', 0);
            $this->artisan('legacy:import-tasker', ['snapshot' => $file, '--scope' => $scope->id, '--commit' => true, '--expect-sha256' => hash('sha256', $bytes)])->assertSuccessful();
            $this->assertDatabaseCount('tasks', 2);
        } finally {
            unlink($file);
        }
    }

    public function test_duplicate_checklist_labels_and_timestamps_are_distinguished_by_import_mappings(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $snapshot['checklist_items'][] = [...$snapshot['checklist_items'][0], 'id' => Str::lower((string) Str::ulid())];
        $importer = app(LegacyTaskerImportService::class);
        $importer->import($scope, $snapshot, true);
        Project::sole()->update(['title' => 'Renamed after import']);

        $again = $importer->import($scope, $snapshot);

        $this->assertSame(0, $again['planned']);
        $this->assertSame(7, array_sum($again['counts']['skip']));
        $this->assertDatabaseCount('task_checklist_items', 2);
        $this->assertDatabaseHas('projects', ['title' => 'Renamed after import']);
    }
}
