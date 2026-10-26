<?php

namespace Tests\Feature;

use App\Models\LedgerAccount;
use App\Models\LedgerCategory;
use App\Models\LedgerTransaction;
use App\Models\LegacyImportRecord;
use App\Models\Project;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LedgerCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $this->actingAs($owner)->withHeaders(['X-App-Request' => 'Zuratax', 'Accept' => 'application/json']);

        return [$owner, $scope, $project, "/api/scopes/{$scope->id}/ledger"];
    }

    public function test_categories_store_parent_and_keywords_and_reject_cycles_foreign_parents_and_incompatible_kinds(): void
    {
        [, $scope, $project, $url] = $this->workspace();
        $parent = LedgerCategory::factory()->create(['project_id' => $project->id, 'kind' => 'expense']);
        $payload = ['project_id' => $project->id, 'title' => 'Ремонт сети', 'kind' => 'expense', 'parent_id' => $parent->id, 'keywords' => ['сервер', 'ремонт сети']];
        $id = $this->postJson("$url/categories", $payload)->assertCreated()->assertJsonPath('data.parent_id', $parent->id)->assertJsonPath('data.keywords', $payload['keywords'])->json('data.id');
        $this->patchJson("$url/categories/{$parent->id}", ['revision' => 1, 'parent_id' => $id])->assertUnprocessable();
        $this->patchJson("$url/categories/$id", ['revision' => 1, 'parent_id' => $id])->assertUnprocessable();
        $other = LedgerCategory::factory()->create();
        $this->postJson("$url/categories", [...$payload, 'parent_id' => $other->id])->assertUnprocessable();
        $otherProject = Project::factory()->create(['scope_id' => $scope->id]);
        $other = LedgerCategory::factory()->create(['project_id' => $otherProject->id]);
        $this->postJson("$url/categories", [...$payload, 'parent_id' => $other->id])->assertUnprocessable();
        $this->postJson("$url/categories", [...$payload, 'kind' => 'income'])->assertUnprocessable();
        $this->patchJson("$url/categories/$id", ['revision' => 1, 'keywords' => [], 'parent_id' => null])->assertOk()->assertJsonPath('data.parent_id', null)->assertJsonPath('data.keywords', []);
        $this->patchJson("$url/categories/$id", ['revision' => 1, 'title' => 'Stale'])->assertConflict();
        $this->postJson("$url/categories", [...$payload, 'keywords' => array_fill(0, 51, 'word')])->assertUnprocessable();
        $this->postJson("$url/categories", [...$payload, 'keywords' => ['   ']])->assertUnprocessable();
    }

    public function test_five_level_limit_accounts_for_entire_subtree_when_reparenting(): void
    {
        [, , $project, $url] = $this->workspace();
        $parent = null;
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $parent = $this->postJson("$url/categories", ['project_id' => $project->id, 'title' => "Уровень $i", 'parent_id' => $parent])->assertCreated()->json('data.id');
            $ids[] = $parent;
        }
        $this->postJson("$url/categories", ['project_id' => $project->id, 'title' => 'Слишком глубоко', 'parent_id' => $parent])->assertUnprocessable();
        $another = LedgerCategory::factory()->create(['project_id' => $project->id]);
        $this->patchJson("$url/categories/{$ids[0]}", ['revision' => 1, 'parent_id' => $another->id])->assertUnprocessable();
        $this->assertDatabaseHas('ledger_categories', ['id' => $ids[0], 'parent_id' => null, 'revision' => 1]);
    }

    public function test_parent_filter_includes_descendants_and_archived_branches_cannot_receive_new_transactions(): void
    {
        [, , $project, $url] = $this->workspace();
        $parent = LedgerCategory::factory()->create(['project_id' => $project->id]);
        $child = LedgerCategory::factory()->create(['project_id' => $project->id, 'parent_id' => $parent->id]);
        $other = LedgerCategory::factory()->create(['project_id' => $project->id]);
        $account = LedgerAccount::factory()->create(['project_id' => $project->id]);
        foreach ([$parent, $child, $other] as $category) {
            LedgerTransaction::factory()->create(['account_id' => $account->id, 'category_id' => $category->id, 'status' => 'posted', 'amount_minor' => '100']);
        }
        $this->getJson("$url/transactions?category_id={$parent->id}")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.totals.0.expense', '200');
        $this->patchJson("$url/categories/{$parent->id}", ['revision' => 1, 'is_archived' => true])->assertOk();
        $this->postJson("$url/categories", ['project_id' => $project->id, 'title' => 'Child', 'parent_id' => $child->id])->assertUnprocessable();
        $this->postJson("$url/transactions", ['account_id' => $account->id, 'category_id' => $child->id, 'kind' => 'expense', 'amount' => '5', 'occurred_on' => '2026-10-26', 'title' => 'Новое', 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable();
        $this->getJson("$url/transactions?category_id={$parent->id}")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_import_migration_restores_only_unchanged_paths_using_saved_source_mapping(): void
    {
        [$owner, $scope, $project] = $this->workspace();
        $parent = LedgerCategory::factory()->create(['project_id' => $project->id, 'title' => 'Дом']);
        $child = LedgerCategory::factory()->create(['project_id' => $project->id, 'title' => 'Дом / Ремонт']);
        $custom = LedgerCategory::factory()->create(['project_id' => $project->id, 'title' => 'Переименовано пользователем']);
        $unmapped = LedgerCategory::factory()->create(['project_id' => $project->id, 'title' => 'Дом / Самостоятельное название']);
        $sourceParent = Str::lower((string) Str::ulid());
        foreach ([[$parent, $sourceParent, null, 'Дом'], [$child, Str::lower((string) Str::ulid()), $sourceParent, 'Ремонт'], [$custom, Str::lower((string) Str::ulid()), $sourceParent, 'Прочее']] as [$category, $source, $ancestor, $name]) {
            LegacyImportRecord::create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'batch_id' => (string) Str::ulid(), 'source' => 'home.zuratax.com', 'source_type' => 'ledger_category', 'source_id' => $source, 'target_type' => 'ledger_category', 'target_id' => $category->id, 'source_hash' => str_repeat('0', 64), 'snapshot' => ['name' => $name, 'parent_id' => $ancestor]]);
        }
        $migration = require glob(database_path('migrations/*_restore_imported_ledger_category_hierarchy.php'))[0];
        $migration->up();
        $migration->up();
        $this->assertDatabaseHas('ledger_categories', ['id' => $child->id, 'title' => 'Ремонт', 'parent_id' => $parent->id, 'revision' => 2]);
        $this->assertDatabaseHas('ledger_categories', ['id' => $custom->id, 'title' => 'Переименовано пользователем', 'parent_id' => null]);
        $this->assertDatabaseHas('ledger_categories', ['id' => $unmapped->id, 'parent_id' => null, 'title' => 'Дом / Самостоятельное название']);
    }
}
