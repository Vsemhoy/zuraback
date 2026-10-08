<?php

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\BookBlock;
use App\Models\BookBlockGroup;
use App\Models\BookPage;
use App\Models\Event;
use App\Models\LoreEntry;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchApiTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax'];

    public function test_search_finds_tasks_projects_and_book_content(): void
    {
        $user = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $user->id]);
        $scope->members()->create(['user_id' => $user->id, 'role' => 'owner', 'joined_at' => now()]);
        $project = Project::factory()->create([
            'scope_id' => $scope->id,
            'created_by' => $user->id,
            'key' => 'ORB',
            'title' => 'Орбитальный проект',
        ]);
        $task = Task::factory()->create([
            'scope_id' => $scope->id,
            'project_id' => $project->id,
            'created_by' => $user->id,
            'task_key' => 'ORB-7',
            'title' => 'Настроить орбитальный шлюз',
            'description' => 'Проверить телеметрию станции',
        ]);
        $book = Book::factory()->create([
            'scope_id' => $scope->id,
            'project_id' => $project->id,
            'created_by' => $user->id,
            'title' => 'Орбитальная документация',
        ]);
        $page = BookPage::factory()->create(['book_id' => $book->id, 'created_by' => $user->id, 'title' => 'Шлюз']);
        $group = BookBlockGroup::factory()->create(['page_id' => $page->id, 'created_by' => $user->id, 'type' => 'markdown']);
        $block = BookBlock::factory()->create([
            'group_id' => $group->id,
            'created_by' => $user->id,
            'content' => 'Секреты орбитальной телеметрии находятся здесь.',
        ]);
        $group->update(['master_block_id' => $block->id]);

        $response = $this->actingAs($user)
            ->withHeaders(self::HEADERS)
            ->getJson("/api/scopes/{$scope->id}/search?q=".urlencode('орбиталь'))
            ->assertOk();

        $ids = collect($response->json('data.results'))->pluck('id');
        $this->assertTrue($ids->contains($task->id));
        $this->assertTrue($ids->contains($project->id));
        $this->assertTrue($ids->contains($book->id));
        $this->assertTrue($ids->contains($group->id));
    }

    public function test_search_respects_restricted_project_and_book_access(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $scope->members()->create([
            'user_id' => $member->id,
            'role' => 'member',
            'permissions' => ['allow' => ['task.view', 'book.view'], 'deny' => []],
            'project_access_mode' => 'restricted',
            'book_access_mode' => 'projects',
            'joined_at' => now(),
        ]);
        $allowed = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'title' => 'Разрешённый спутник', 'key' => 'SAT']);
        $hidden = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'title' => 'Скрытый спутник', 'key' => 'SEC']);
        ProjectMember::factory()->create(['project_id' => $allowed->id, 'user_id' => $member->id, 'assigned_by' => $owner->id, 'is_active' => true]);
        $allowedTask = Task::factory()->create(['scope_id' => $scope->id, 'project_id' => $allowed->id, 'created_by' => $owner->id, 'title' => 'Спутник доступный']);
        $hiddenTask = Task::factory()->create(['scope_id' => $scope->id, 'project_id' => $hidden->id, 'created_by' => $owner->id, 'title' => 'Спутник закрытый']);
        $allowedBook = Book::factory()->create(['scope_id' => $scope->id, 'project_id' => $allowed->id, 'created_by' => $owner->id, 'title' => 'Спутник handbook', 'visibility' => 'scope']);
        $hiddenBook = Book::factory()->create(['scope_id' => $scope->id, 'project_id' => $hidden->id, 'created_by' => $owner->id, 'title' => 'Спутник hidden', 'visibility' => 'scope']);

        $response = $this->actingAs($member)
            ->withHeaders(self::HEADERS)
            ->getJson("/api/scopes/{$scope->id}/search?q=".urlencode('спутник'))
            ->assertOk();

        $ids = collect($response->json('data.results'))->pluck('id');
        $this->assertTrue($ids->contains($allowed->id));
        $this->assertTrue($ids->contains($allowedTask->id));
        $this->assertTrue($ids->contains($allowedBook->id));
        $this->assertFalse($ids->contains($hidden->id));
        $this->assertFalse($ids->contains($hiddenTask->id));
        $this->assertFalse($ids->contains($hiddenBook->id));
    }

    public function test_completed_assignee_and_task_author_filters_are_independent_and_composable(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $base = ['scope_id' => $scope->id, 'created_by' => $owner->id, 'assignee_id' => $assignee->id, 'title' => 'Searchable task', 'status' => 'done'];
        $done = Task::factory()->create($base);
        $pending = Task::factory()->create([...$base, 'status' => 'todo']);
        $otherAuthor = Task::factory()->create([...$base, 'created_by' => $assignee->id]);
        Task::factory()->create([...$base, 'assignee_id' => $owner->id]);
        Book::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'title' => 'Searchable book']);
        $url = "/api/scopes/{$scope->id}/search?q=Searchable";
        $this->actingAs($owner)->withHeaders(self::HEADERS);
        $this->getJson($url.'&completed_by='.$assignee->id)->assertOk()->assertJsonCount(2, 'data.results')
            ->assertJsonFragment(['id' => $done->id])->assertJsonFragment(['id' => $otherAuthor->id])->assertJsonMissing(['id' => $pending->id]);
        $this->getJson($url.'&created_by='.$owner->id)->assertOk()->assertJsonCount(3, 'data.results');
        $this->getJson($url.'&completed_by='.$assignee->id.'&created_by='.$owner->id)->assertOk()
            ->assertJsonCount(1, 'data.results')->assertJsonPath('data.results.0.id', $done->id)
            ->assertJsonPath('data.results.0.meta.user.name', $assignee->name);
        $this->getJson($url.'&completed_by='.$assignee->id.'&status=todo')->assertOk()->assertJsonCount(0, 'data.results');
    }

    public function test_lore_and_event_search_respects_visibility_projects_scope_and_filters(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'permissions' => ['allow' => ['task.view'], 'deny' => []], 'project_access_mode' => 'restricted']);
        $allowed = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'show_in_eventor' => true]);
        $hidden = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        ProjectMember::factory()->create(['project_id' => $allowed->id, 'user_id' => $member->id, 'assigned_by' => $owner->id, 'is_active' => true]);
        $entry = LoreEntry::create(['scope_id' => $scope->id, 'project_id' => $allowed->id, 'created_by' => $owner->id, 'code' => 'NAV', 'visibility' => 'scope']);
        $entry->revisions()->create(['created_by' => $owner->id, 'version' => 1, 'title' => 'Navigation lore', 'content' => 'Searchable lore content', 'effective_from' => now(), 'status' => 'active']);
        $entry->revisions()->create(['created_by' => $owner->id, 'version' => 2, 'title' => 'New title', 'content' => 'New text', 'effective_from' => now(), 'status' => 'active']);
        $event = Event::factory()->create(['scope_id' => $scope->id, 'project_id' => $allowed->id, 'created_by' => $owner->id, 'title' => 'Navigation event', 'content' => 'Searchable event content', 'visibility' => 'scope']);
        $privateEvent = Event::factory()->create(['scope_id' => $scope->id, 'project_id' => $allowed->id, 'created_by' => $owner->id, 'title' => 'Searchable private', 'visibility' => 'private']);
        foreach ([['project_id' => $hidden->id], ['project_id' => null], ['scope_id' => Scope::factory()->create()->id, 'project_id' => null], ['visibility' => 'private'], ['deleted_at' => now()]] as $index => $overrides) {
            $attributes = ['scope_id' => $scope->id, 'project_id' => $allowed->id, 'created_by' => $owner->id, 'visibility' => 'scope', ...$overrides];
            Event::factory()->create([...$attributes, 'title' => 'Searchable hidden']);
            $hiddenEntry = LoreEntry::create([...$attributes, 'code' => 'HIDDEN-'.$index]);
            if (isset($overrides['deleted_at'])) {
                $hiddenEntry->delete();
            }
            $hiddenEntry->revisions()->create(['created_by' => $owner->id, 'version' => 1, 'title' => 'Searchable hidden', 'content' => 'Hidden', 'effective_from' => now()]);
        }
        $url = "/api/scopes/{$scope->id}/search?q=Searchable&types=lore,event";
        $this->actingAs($member)->withHeaders(self::HEADERS)->getJson($url)->assertOk()->assertJsonCount(2, 'data.results')
            ->assertJsonFragment(['id' => $entry->id])->assertJsonFragment(['id' => $event->id])
            ->assertJsonFragment(['title' => 'Navigation lore'])->assertJsonFragment(['href' => "/events?event={$event->id}"]);
        $this->getJson($url.'&project_id='.$hidden->id)->assertOk()->assertJsonCount(0, 'data.results');
        $this->getJson($url.'&user_id='.$member->id)->assertOk()->assertJsonCount(0, 'data.results');
        $this->getJson($url.'&date_from='.now()->addDay()->toDateString())->assertOk()->assertJsonCount(0, 'data.results');
        $this->getJson("/api/scopes/{$scope->id}/search?q=NAV&types=lore")->assertOk()->assertJsonPath('data.results.0.id', $entry->id);
        $this->actingAs($owner)->getJson($url)->assertOk()->assertJsonFragment(['id' => $privateEvent->id]);
        $scope->members()->where('user_id', $member->id)->update(['permissions' => ['allow' => [], 'deny' => ['task.view']]]);
        $this->actingAs($member)->getJson($url)->assertOk()->assertJsonCount(0, 'data.results');
    }

    public function test_search_requires_two_characters(): void
    {
        $user = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $user->id]);
        $scope->members()->create(['user_id' => $user->id, 'role' => 'owner', 'joined_at' => now()]);

        $this->actingAs($user)
            ->withHeaders(self::HEADERS)
            ->getJson("/api/scopes/{$scope->id}/search?q=x")
            ->assertUnprocessable();
    }
}
