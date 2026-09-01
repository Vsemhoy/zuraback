<?php

namespace Tests\Feature\Api;

use App\Models\Book;
use App\Models\BookBlock;
use App\Models\BookBlockGroup;
use App\Models\BookPage;
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
        $allowedBook = Book::factory()->create(['scope_id' => $scope->id, 'project_id' => $allowed->id, 'created_by' => $owner->id, 'title' => 'Спутник handbook']);
        $hiddenBook = Book::factory()->create(['scope_id' => $scope->id, 'project_id' => $hidden->id, 'created_by' => $owner->id, 'title' => 'Спутник hidden']);

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
