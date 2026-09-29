<?php

namespace Tests\Feature\Api;

use App\Models\Kpi;
use App\Models\MonthlyReport;
use App\Models\MonthlyTaskPlan;
use App\Models\Project;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class MonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax'];

    private const FILTERS = ['month' => '2026-09', 'timezone' => 'Europe/Moscow'];

    public function test_reports_only_qualified_bonus_kpis_once_and_all_completed_work(): void
    {
        [$user, $scope] = $this->workspace();
        $kpi = Kpi::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'kind' => 'bonus', 'points' => 120, 'minimum_completed_tasks' => 2]);
        $under = Kpi::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'kind' => 'bonus', 'points' => 15, 'minimum_completed_tasks' => 2]);
        $general = Kpi::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'kind' => 'salary', 'points' => 40]);
        $this->task($user, $scope, ['kpi_id' => $kpi->id, 'completed_at' => '2026-08-31 21:00:00']);
        $this->task($user, $scope, ['kpi_id' => $kpi->id]);
        $this->task($user, $scope, ['kpi_id' => $under->id]);
        $this->task($user, $scope, ['kpi_id' => $general->id]);
        $this->task($user, $scope, ['assignee_id' => null]);
        $this->task($user, $scope, ['completed_at' => '2026-09-30 21:00:00']);
        $this->task($user, $scope, ['completed_at' => '2026-08-31 20:59:59']);
        $this->task($user, $scope, ['status' => 'in_progress']);
        $this->task($user, $scope)->delete();

        $this->actingAs($user)->withHeaders(self::HEADERS)->getJson($this->url($scope))
            ->assertOk()->assertJsonCount(5, 'data.completed')->assertJsonCount(1, 'data.kpis')
            ->assertJsonPath('data.kpis.0.points', 120)->assertJsonCount(2, 'data.kpis.0.tasks')
            ->assertJsonPath('data.summary.0.bonus_points', 120)->assertJsonPath('data.summary.0.bonus_percent', 75)
            ->assertJsonPath('data.plan_month', '2026-10')->assertJsonMissingPath('data.salary_points');
    }

    public function test_filters_a_person_without_combining_kpi_thresholds_between_people(): void
    {
        [$owner, $scope] = $this->workspace();
        $person = User::factory()->create();
        $scope->members()->create(['user_id' => $person->id, 'role' => 'member', 'project_access_mode' => 'all']);
        $kpi = Kpi::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'kind' => 'bonus', 'points' => 55, 'minimum_completed_tasks' => 2]);
        $this->task($owner, $scope, ['kpi_id' => $kpi->id]);
        $this->task($owner, $scope, ['kpi_id' => $kpi->id, 'assignee_id' => $person->id]);
        $this->actingAs($owner)->withHeaders(self::HEADERS)->getJson($this->url($scope, ['user_id' => $person->id]))
            ->assertOk()->assertJsonCount(1, 'data.completed')->assertJsonCount(0, 'data.kpis')->assertJsonCount(1, 'data.summary');
    }

    public function test_hides_private_projects_and_rechecks_archive_access_after_revocation(): void
    {
        Storage::fake('reports');
        [$owner, $scope] = $this->workspace();
        $member = User::factory()->create();
        $membership = $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'project_access_mode' => 'all']);
        $public = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope']);
        $private = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'private']);
        $this->task($owner, $scope, ['project_id' => $public->id]);
        $secret = $this->task($owner, $scope, ['project_id' => $private->id]);
        $this->actingAs($member)->withHeaders(self::HEADERS)->getJson($this->url($scope))
            ->assertOk()->assertJsonCount(1, 'data.completed')->assertJsonMissing(['id' => $secret->id]);
        $id = $this->postJson($this->base($scope).'/archives', self::FILTERS)->assertCreated()->json('data.id');
        $membership->update(['project_access_mode' => 'none']);
        $this->getJson($this->base($scope).'/archives/'.$id.'/download')->assertNotFound();
    }

    public function test_archive_is_a_private_immutable_xlsx_snapshot_with_four_sheets_and_safe_strings(): void
    {
        Storage::fake('reports');
        [$user, $scope] = $this->workspace();
        $kpi = Kpi::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'kind' => 'bonus', 'points' => 55, 'minimum_completed_tasks' => 1, 'name' => 'Закрытие обращений']);
        $task = $this->task($user, $scope, ['kpi_id' => $kpi->id, 'title' => '=HYPERLINK("https://example.invalid","test")', 'result' => '<script>& текст']);
        $planned = $this->task($user, $scope, ['status' => 'todo', 'completed_at' => null, 'title' => 'Подготовить инфраструктуру склада']);
        MonthlyTaskPlan::query()->create(['scope_id' => $scope->id, 'task_id' => $planned->id, 'month' => '2026-10', 'assignee_id' => $user->id, 'created_by' => $user->id, 'expected_result' => 'Подготовить тестовый стенд и проверить резервирование.']);
        $id = $this->actingAs($user)->withHeaders(self::HEADERS)->postJson($this->base($scope).'/archives', self::FILTERS)
            ->assertCreated()->assertJsonMissingPath('data.file_path')->assertJsonMissingPath('data.snapshot')->json('data.id');
        $report = MonthlyReport::query()->findOrFail($id);
        Storage::disk('reports')->assertExists($report->file_path);
        $path = Storage::disk('reports')->path($report->file_path);
        $hash = hash_file('sha256', $path);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $book = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
        $this->assertCount(4, $book->sheets->sheet);
        $sheet = $zip->getFromName('xl/worksheets/sheet3.xml');
        $this->assertStringContainsString('t="inlineStr"', $sheet);
        $this->assertStringContainsString('=HYPERLINK(', $sheet);
        $this->assertStringContainsString('&lt;script&gt;&amp;', $sheet);
        $this->assertStringNotContainsString('<f>', $sheet);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $this->assertNotFalse(simplexml_load_string($zip->getFromIndex($i)));
        }
        $zip->close();
        $task->update(['title' => 'Changed', 'status' => 'todo']);
        $this->getJson($this->base($scope).'/archives/'.$id.'/download')->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertSame($hash, hash_file('sha256', $path));
        $this->assertSame($hash, $report->sha256);
        $this->assertStringStartsWith('=HYPERLINK', $report->snapshot['completed'][0]['title']);
        $this->assertSame(55, $report->snapshot['summary'][0]['bonus_percent']);
    }

    public function test_plan_is_month_specific_idempotent_and_does_not_move_calendar_dates(): void
    {
        [$user, $scope] = $this->workspace();
        $task = $this->task($user, $scope, ['status' => 'todo', 'completed_at' => null, 'due_at' => '2026-09-20 10:00:00']);
        $payload = ['task_id' => $task->id, 'month' => '2026-10', 'assignee_id' => $user->id, 'expected_result' => 'Первый этап'];
        $id = $this->actingAs($user)->withHeaders(self::HEADERS)->postJson($this->base($scope).'/plans', $payload)->assertCreated()->json('data.id');
        $this->postJson($this->base($scope).'/plans', [...$payload, 'expected_result' => 'Обновлённый этап'])->assertOk();
        $this->postJson($this->base($scope).'/plans', [...$payload, 'month' => '2026-11'])->assertCreated();
        $this->assertDatabaseCount('monthly_task_plans', 2);
        $this->assertSame('2026-09-20 10:00:00', $task->fresh()->due_at->format('Y-m-d H:i:s'));
        $this->getJson($this->url($scope))->assertOk()->assertJsonCount(1, 'data.plan')->assertJsonPath('data.plan.0.expected_result', 'Обновлённый этап');
        $this->deleteJson($this->base($scope).'/plans/'.$id)->assertNoContent();
        $this->assertDatabaseMissing('monthly_task_plans', ['id' => $id]);
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $task->id, 'action' => 'task.monthly_plan_removed']);
    }

    public function test_rejects_foreign_tasks_people_invalid_months_and_completed_tasks(): void
    {
        [$user, $scope] = $this->workspace();
        $foreign = Task::factory()->create();
        $done = $this->task($user, $scope);
        $this->actingAs($user)->withHeaders(self::HEADERS)->postJson($this->base($scope).'/plans', ['task_id' => $foreign->id, 'month' => '2026-10'])->assertNotFound();
        $this->postJson($this->base($scope).'/plans', ['task_id' => $done->id, 'month' => '2026-10'])->assertUnprocessable();
        $this->getJson($this->url($scope, ['month' => '2026-13']))->assertUnprocessable()->assertJsonValidationErrors('month');
        $this->getJson($this->url($scope, ['timezone' => 'Not/AZone']))->assertUnprocessable()->assertJsonValidationErrors('timezone');
        $this->getJson($this->url($scope, ['user_id' => User::factory()->create()->id]))->assertUnprocessable();
        $this->assertDatabaseCount('monthly_task_plans', 0);
    }

    public function test_requires_auth_membership_and_report_write_capability(): void
    {
        Storage::fake('reports');
        [$owner, $scope] = $this->workspace();
        $this->withHeaders(self::HEADERS)->getJson($this->url($scope))->assertUnauthorized();
        $observer = User::factory()->create();
        $scope->members()->create(['user_id' => $observer->id, 'role' => 'observer', 'project_access_mode' => 'all']);
        $this->actingAs($observer)->postJson($this->base($scope).'/archives', self::FILTERS)->assertForbidden();
        $this->assertDatabaseCount('monthly_reports', 0);
        $other = Scope::factory()->create();
        $this->getJson($this->url($other))->assertForbidden();
        Storage::disk('reports')->assertDirectoryEmpty('/');
    }

    private function workspace(): array
    {
        $user = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $user->id]);
        $scope->members()->create(['user_id' => $user->id, 'role' => 'owner', 'project_access_mode' => 'all']);

        return [$user, $scope];
    }

    public function test_does_not_count_non_executors_or_carry_points_to_next_month(): void
    {
        [$user, $scope] = $this->workspace();
        $user->update(['is_executor' => false]);
        $kpi = Kpi::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'kind' => 'bonus', 'points' => 55, 'minimum_completed_tasks' => 1]);
        $this->task($user, $scope, ['kpi_id' => $kpi->id]);
        $this->actingAs($user)->withHeaders(self::HEADERS)->getJson($this->url($scope))
            ->assertOk()->assertJsonCount(1, 'data.completed')->assertJsonCount(0, 'data.kpis')->assertJsonPath('data.summary.0.bonus_percent', 0);
        $this->getJson($this->url($scope, ['month' => '2026-10']))->assertOk()->assertJsonCount(0, 'data.completed')->assertJsonCount(0, 'data.kpis');
    }

    public function test_refuses_export_when_disk_reserve_would_be_consumed(): void
    {
        Storage::fake('reports');
        config(['filer.reserve_bytes' => PHP_INT_MAX]);
        [$user, $scope] = $this->workspace();
        $this->actingAs($user)->withHeaders(self::HEADERS)->postJson($this->base($scope).'/archives', self::FILTERS)->assertStatus(507);
        $this->assertDatabaseCount('monthly_reports', 0);
        $this->assertSame([], Storage::disk('reports')->allFiles());
    }

    public function test_archive_and_plan_ids_cannot_be_used_in_another_scope(): void
    {
        Storage::fake('reports');
        [$user, $scope] = $this->workspace();
        $other = Scope::factory()->create(['owner_id' => $user->id]);
        $task = $this->task($user, $scope, ['status' => 'todo', 'completed_at' => null]);
        $planId = $this->actingAs($user)->withHeaders(self::HEADERS)->postJson($this->base($scope).'/plans', ['month' => '2026-10', 'task_id' => $task->id])->assertCreated()->json('data.id');
        $id = $this->postJson($this->base($scope).'/archives', self::FILTERS)->assertCreated()->json('data.id');
        $this->getJson($this->base($other).'/archives/'.$id.'/download')->assertNotFound();
        $this->deleteJson($this->base($other).'/plans/'.$planId)->assertNotFound();
        $this->assertDatabaseHas('monthly_task_plans', ['id' => $planId]);
    }

    public function test_long_results_remain_complete_in_xlsx_continuation_rows(): void
    {
        Storage::fake('reports');
        [$user, $scope] = $this->workspace();
        $result = str_repeat("Проверено резервирование и отгрузка.\n", 1500);
        $this->task($user, $scope, ['result' => $result]);
        $id = $this->actingAs($user)->withHeaders(self::HEADERS)->postJson($this->base($scope).'/archives', self::FILTERS)->assertCreated()->json('data.id');
        $report = MonthlyReport::query()->findOrFail($id);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('reports')->path($report->file_path)));
        $xml = simplexml_load_string($zip->getFromName('xl/worksheets/sheet3.xml'));
        $text = '';
        foreach ($xml->sheetData->row as $row) {
            if ((int) $row['r'] < 5) {
                continue;
            }
            foreach ($row->c as $cell) {
                if (str_starts_with((string) $cell['r'], 'G')) {
                    $text .= (string) $cell->is->t;
                }
            }
        }
        $zip->close();
        $this->assertSame($result, $text);
    }

    private function task(User $user, Scope $scope, array $data = []): Task
    {
        return Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $user->id, 'assignee_id' => $user->id, 'status' => 'done', 'completed_at' => '2026-09-15 10:00:00', ...$data]);
    }

    private function base(Scope $scope): string
    {
        return "/api/scopes/{$scope->id}/reports/monthly";
    }

    private function url(Scope $scope, array $filters = []): string
    {
        return $this->base($scope).'?'.http_build_query([...self::FILTERS, ...$filters]);
    }
}
