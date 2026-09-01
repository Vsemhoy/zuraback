<?php

namespace Tests\Feature\Api;

use App\Models\Comment;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax'];

    public function test_dashboard_combines_personal_work_book_comments_kpis_and_recent_entities(): void
    {
        $owner = User::factory()->create(['name' => 'Owner']);
        $colleague = User::factory()->create(['name' => 'Colleague', 'is_executor' => false]);
        $scope = Scope::query()->create(['owner_id' => $owner->id, 'name' => 'Work', 'slug' => 'work']);
        $scope->members()->create(['user_id' => $owner->id, 'role' => 'owner', 'book_access_mode' => 'all', 'joined_at' => now()]);
        $scope->members()->create(['user_id' => $colleague->id, 'role' => 'member', 'joined_at' => now()]);
        $project = $scope->projects()->create(['created_by' => $owner->id, 'title' => 'Dashboard project', 'key' => 'DSH']);
        $kpi = $scope->kpis()->create(['created_by' => $owner->id, 'name' => 'Ship work', 'kind' => 'bonus', 'points' => 25, 'minimum_completed_tasks' => 1]);
        $openTask = $scope->tasks()->create(['project_id' => $project->id, 'created_by' => $owner->id, 'assignee_id' => $owner->id, 'title' => 'My open task', 'status' => 'todo']);
        $scope->tasks()->create(['project_id' => $project->id, 'created_by' => $owner->id, 'assignee_id' => $owner->id, 'kpi_id' => $kpi->id, 'title' => 'My completed KPI task', 'status' => 'done', 'completed_at' => now()]);
        $book = $scope->books()->create(['created_by' => $owner->id, 'title' => 'Fresh handbook', 'visibility' => 'scope']);
        $page = $book->pages()->create(['created_by' => $owner->id, 'title' => 'Dashboard page']);
        Comment::query()->create(['scope_id' => $scope->id, 'commentable_type' => 'book_page', 'commentable_id' => $page->id, 'created_by' => $colleague->id, 'content' => 'Проверь этот раздел']);

        $this->actingAs($owner)->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.summary.my_open_tasks', 1)
            ->assertJsonPath('data.my_tasks.0.id', $openTask->id)
            ->assertJsonPath('data.book_comments.0.content', 'Проверь этот раздел')
            ->assertJsonPath('data.book_comments.0.book.id', $book->id)
            ->assertJsonPath('data.kpi.me.bonus_points', 25)
            ->assertJsonCount(0, 'data.kpi.team')
            ->assertJsonPath('data.recent.projects.0.id', $project->id)
            ->assertJsonPath('data.recent.books.0.id', $book->id);
    }
}
