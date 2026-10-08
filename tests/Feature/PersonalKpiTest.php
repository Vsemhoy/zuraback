<?php

namespace Tests\Feature;

use App\Models\Kpi;
use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonalKpiTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax'];

    public function test_profiles_preserve_past_months_and_calculate_people_independently(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 8));
        $owner = User::factory()->create(['is_executor' => true]);
        $colleague = User::factory()->create(['is_executor' => true]);
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $scope->members()->create(['user_id' => $colleague->id, 'role' => 'member']);
        $kpi = Kpi::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'kind' => 'bonus', 'points' => 60, 'minimum_completed_tasks' => 1]);
        foreach ([$owner, $colleague] as $user) {
            foreach (['2026-09-10', '2026-10-10', '2026-11-10'] as $date) {
                Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'assignee_id' => $user->id, 'kpi_id' => $kpi->id, 'status' => 'done', 'due_at' => $date, 'completed_at' => now()]);
            }
        }
        $base = "/api/scopes/{$scope->id}";
        $this->actingAs($owner)->withHeaders(self::HEADERS);
        $payload = ['effective_month' => '2026-10', 'targets' => ['salary_target_points' => 100, 'bonus_target_points' => 40, 'bonus_cap_percent' => 40], 'items' => [['id' => $kpi->id, 'name' => 'Personal', 'kind' => 'bonus', 'points' => 30, 'minimum_completed_tasks' => 1, 'is_active' => true]]];
        $this->putJson($base.'/kpi-profiles/'.$owner->id, $payload)->assertSuccessful();
        $this->getJson($base.'/kpis/stats?month=2026-09&user_id='.$owner->id)->assertOk()->assertJsonPath('data.people.0.payable_bonus_percent', 60);
        $this->getJson($base.'/kpis/stats?month=2026-10&user_id='.$owner->id)->assertOk()->assertJsonPath('data.people.0.payable_bonus_percent', 30)->assertJsonPath('data.people.0.bonus_target', 40);
        $this->getJson($base.'/kpis/stats?month=2026-10&user_id='.$colleague->id)->assertOk()->assertJsonPath('data.people.0.payable_bonus_percent', 60);
        $this->getJson($base.'/dashboard')->assertOk()->assertJsonPath('data.kpi.me.payable_bonus_percent', 30);
        $this->putJson($base.'/kpi-profiles/'.$owner->id, [...$payload, 'effective_month' => '2026-11', 'items' => []])->assertSuccessful();
        $this->getJson($base.'/kpis/stats?month=2026-10&user_id='.$owner->id)->assertOk()->assertJsonPath('data.people.0.payable_bonus_percent', 30);
        $this->getJson($base.'/kpis/stats?month=2026-11&user_id='.$owner->id)->assertOk()->assertJsonPath('data.people.0.payable_bonus_percent', 0);
        $this->putJson($base.'/kpi-profiles/'.$owner->id, [...$payload, 'effective_month' => '2026-09'])->assertUnprocessable();
        $this->actingAs($colleague)->putJson($base.'/kpi-profiles/'.$colleague->id, $payload)->assertForbidden();
    }

    public function test_profiles_reject_foreign_kpis_and_new_items_do_not_change_other_people_or_history(): void
    {
        $owner = User::factory()->create(['is_executor' => true]);
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $foreign = Kpi::factory()->create();
        $base = "/api/scopes/{$scope->id}";
        $this->actingAs($owner)->withHeaders(self::HEADERS);
        $item = ['name' => 'Only mine', 'kind' => 'salary', 'points' => 20, 'minimum_completed_tasks' => 2, 'is_active' => true];
        $payload = ['effective_month' => now()->format('Y-m'), 'targets' => ['salary_target_points' => 80, 'bonus_target_points' => 50, 'bonus_cap_percent' => 50], 'items' => [$item]];
        $this->putJson($base.'/kpi-profiles/'.$owner->id, [...$payload, 'items' => [[...$item, 'id' => $foreign->id]]])->assertUnprocessable();
        $this->putJson($base.'/kpi-profiles/'.$owner->id, $payload)->assertSuccessful();
        $this->getJson($base.'/kpis?user_id='.$owner->id.'&month='.now()->format('Y-m'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.points', 20);
        $this->getJson($base.'/kpi-profiles/'.$owner->id.'?month='.now()->subMonth()->format('Y-m'))->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_catalog_edits_and_target_changes_preserve_previous_months(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 8));
        $owner = User::factory()->create(['is_executor' => true]);
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $kpi = Kpi::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'kind' => 'bonus', 'points' => 60, 'minimum_completed_tasks' => 1]);
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'assignee_id' => $owner->id, 'kpi_id' => $kpi->id, 'status' => 'done', 'due_at' => '2026-09-10', 'completed_at' => now()]);
        $base = "/api/scopes/{$scope->id}";
        $this->actingAs($owner)->withHeaders(self::HEADERS);
        $this->patchJson($base.'/kpis/'.$kpi->id, ['points' => 15])->assertOk();
        $this->putJson($base.'/kpis/settings', ['salary_target_points' => 100, 'bonus_target_points' => 20, 'bonus_cap_percent' => 20])->assertOk();
        $this->getJson($base.'/kpis/stats?month=2026-09&user_id='.$owner->id)->assertOk()->assertJsonPath('data.people.0.payable_bonus_percent', 60)->assertJsonPath('data.people.0.bonus_target', 75);
        $this->getJson($base.'/tasks/'.$task->id)->assertOk()->assertJsonPath('data.kpi.points', 60);
        $this->deleteJson($base.'/kpis/'.$kpi->id)->assertNoContent();
        $this->getJson($base.'/kpis/stats?month=2026-09&user_id='.$owner->id)->assertOk()->assertJsonPath('data.people.0.payable_bonus_percent', 60);
    }
}
