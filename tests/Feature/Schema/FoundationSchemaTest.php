<?php

namespace Tests\Feature\Schema;

use App\Models\Book;
use App\Models\BookBlock;
use App\Models\BookBlockGroup;
use App\Models\BookPage;
use App\Models\BookSpace;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\EventType;
use App\Models\Fact;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FoundationSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_foundation_tables_are_created(): void
    {
        $tables = [
            'scopes', 'scope_members', 'projects', 'tasks', 'facts', 'book_spaces', 'books',
            'book_pages', 'book_block_groups', 'book_blocks', 'event_types', 'event_sections',
            'events', 'responsibility_areas', 'compensation_periods', 'entity_links', 'tags',
            'taggables', 'comments', 'activity_logs', 'task_key_aliases', 'task_checklist_items',
            'project_members', 'contractor_delegations', 'task_planner_tails',
        ];

        foreach ($tables as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_booker_keeps_versioned_drawing_and_svg_payloads(): void
    {
        $user = User::factory()->create();
        $scope = Scope::query()->create(['owner_id' => $user->id, 'name' => 'Work', 'slug' => 'work']);
        $space = BookSpace::query()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'title' => 'Engineering']);
        $book = Book::query()->create(['scope_id' => $scope->id, 'space_id' => $space->id, 'created_by' => $user->id, 'title' => 'Runbook']);
        $page = BookPage::query()->create(['book_id' => $book->id, 'created_by' => $user->id, 'title' => 'Network']);
        $group = BookBlockGroup::query()->create(['page_id' => $page->id, 'created_by' => $user->id, 'type' => 'excalidraw']);
        $block = BookBlock::query()->create([
            'group_id' => $group->id,
            'created_by' => $user->id,
            'payload' => ['scene' => ['elements' => []], 'svg' => '<svg viewBox="0 0 10 10"></svg>'],
        ]);
        $group->update(['master_block_id' => $block->id]);

        $this->assertSame('excalidraw', $group->type);
        $this->assertSame('<svg viewBox="0 0 10 10"></svg>', $block->payload['svg']);
        $this->assertSame($block->id, $group->fresh()->master_block_id);
    }

    public function test_task_event_factor_and_project_can_be_cross_linked(): void
    {
        $user = User::factory()->create();
        $scope = Scope::query()->create(['owner_id' => $user->id, 'name' => 'Work', 'slug' => 'work']);
        $project = Project::query()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'title' => 'Warehouse']);
        $task = Task::query()->create(['scope_id' => $scope->id, 'project_id' => $project->id, 'created_by' => $user->id, 'title' => 'Fix import']);
        Fact::query()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'label' => 'Endpoint', 'value' => '/import']);
        $type = EventType::query()->create(['scope_id' => $scope->id, 'name' => 'Incident']);
        $section = EventSection::query()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'name' => 'Operations']);
        $event = Event::query()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'type_id' => $type->id, 'section_id' => $section->id, 'title' => 'Import failed']);

        $link = $task->outboundLinks()->create([
            'scope_id' => $scope->id,
            'target_type' => $event->getMorphClass(),
            'target_id' => $event->id,
            'relation' => 'caused_by',
            'created_by' => $user->id,
        ])->load(['source', 'target']);

        $this->assertTrue($link->source->is($task));
        $this->assertTrue($link->target->is($event));
    }
}
