<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Scope;
use App\Models\Thing;
use App\Models\ThingAssignment;
use App\Models\ThingComposition;
use App\Models\ThingSupplier;
use App\Models\ThingType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Scope $scope;

    private User $owner;

    private ThingType $type;

    private string $url;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->scope = Scope::factory()->create(['owner_id' => $this->owner->id]);
        $this->type = ThingType::factory()->create(['scope_id' => $this->scope->id]);
        $this->url = "/api/scopes/{$this->scope->id}/equip";
        $this->actingAs($this->owner)->withHeaders(['X-App-Request' => 'Zuratax', 'Accept' => 'application/json']);
    }

    private function thing(array $attributes = []): Thing
    {
        return Thing::factory()->create(['scope_id' => $this->scope->id, 'thing_type_id' => $this->type->id, ...$attributes]);
    }

    private function person(array $attributes = []): User
    {
        $person = User::factory()->create($attributes);
        $this->scope->members()->create(['user_id' => $person->id, 'role' => 'member']);

        return $person;
    }

    private function operate(Thing $thing, array $data)
    {
        return $this->postJson("{$this->url}/{$thing->id}/operations", ['revision' => $thing->fresh()->revision, ...$data]);
    }

    private function attach(Thing $parent, Thing $child): void
    {
        $this->operate($parent, ['operation' => 'attach', 'child_id' => $child->id, 'child_revision' => $child->fresh()->revision])->assertOk();
    }

    private function revisions(Thing $root): array
    {
        $state = $this->getJson("{$this->url}/{$root->id}/operations")->assertOk()->json('data');

        return collect($state['assembly'])->pluck('revision', 'id')->all();
    }

    private function assign(Thing $root, User $person): void
    {
        $this->operate($root, ['operation' => 'assign', 'expected_revisions' => $this->revisions($root), 'user_id' => $person->id, 'responsibility' => 'use', 'note' => 'Выдача на рабочее место'])->assertOk();
    }

    public function test_recursive_issue_transfer_and_return_preserve_periods_and_ownership(): void
    {
        $alice = $this->person();
        $bob = $this->person();
        $desk = $this->thing(['container_kind' => 'logical']);
        $pc = $this->thing(['container_kind' => 'physical']);
        $gpu = $this->thing(['owner_kind' => 'employee', 'owner_id' => $alice->id]);
        $this->attach($pc, $gpu);
        $this->attach($desk, $pc);
        $this->assign($desk, $alice);
        $this->assertDatabaseCount('thing_assignments', 3);
        $this->assertEquals(3, ThingAssignment::where('active', 1)->where('user_id', $alice->id)->count());
        $this->travel(1)->minutes();
        $this->assign($desk, $bob);
        $this->assertDatabaseCount('thing_assignments', 6);
        $this->assertEquals(3, ThingAssignment::whereNull('active')->whereNotNull('ended_at')->count());
        $this->assertEquals(3, ThingAssignment::where('active', 1)->where('user_id', $bob->id)->count());
        $this->assertDatabaseHas('things', ['id' => $gpu->id, 'owner_kind' => 'employee', 'owner_id' => $alice->id]);
        $this->travel(1)->minutes();
        $this->operate($desk, ['operation' => 'return', 'expected_revisions' => $this->revisions($desk), 'note' => 'Сдано'])->assertOk();
        $this->assertDatabaseCount('thing_assignments', 6);
        $this->assertEquals(0, ThingAssignment::where('active', 1)->count());
        $this->getJson("{$this->url}/{$gpu->id}/assignments")->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.closing_note', 'Сдано');
        $this->assertEquals(3, ActivityLog::where('action', 'equip.returned')->count());
        $this->assertEquals(2, ThingComposition::where('active', 1)->count());
    }

    public function test_cycle_double_physical_parent_and_duplicate_links_are_rejected(): void
    {
        $a = $this->thing(['container_kind' => 'physical']);
        $b = $this->thing(['container_kind' => 'physical']);
        $c = $this->thing(['container_kind' => 'logical']);
        $this->attach($a, $b);
        $this->attach($c, $a);
        foreach ([[$a, $a], [$a, $b], [$b, $c]] as [$parent, $child]) {
            $this->operate($parent, ['operation' => 'attach', 'child_id' => $child->id, 'child_revision' => $child->fresh()->revision])->assertUnprocessable();
        }
        $other = $this->thing(['container_kind' => 'physical']);
        $this->operate($other, ['operation' => 'attach', 'child_id' => $b->id, 'child_revision' => $b->fresh()->revision])->assertUnprocessable();
        $this->operate($a, ['operation' => 'container', 'container_kind' => 'none'])->assertUnprocessable();
        $this->assertDatabaseCount('thing_compositions', 2);
    }

    public function test_logical_groups_can_share_components_and_recursive_issue_deduplicates(): void
    {
        $root = $this->thing(['container_kind' => 'logical']);
        $a = $this->thing(['container_kind' => 'logical']);
        $b = $this->thing(['container_kind' => 'logical']);
        $leaf = $this->thing();
        $this->attach($a, $leaf);
        $this->attach($b, $leaf);
        $this->attach($root, $a);
        $this->attach($root, $b);
        $person = $this->person();
        $this->assign($root, $person);
        $this->assertDatabaseCount('thing_assignments', 4);
        $this->assign($root, $person);
        $this->assertDatabaseCount('thing_assignments', 4);
    }

    public function test_disband_and_reattach_preserve_relations_assignments_and_nested_assemblies(): void
    {
        $root = $this->thing(['container_kind' => 'logical']);
        $pc = $this->thing(['container_kind' => 'physical']);
        $gpu = $this->thing();
        $this->attach($pc, $gpu);
        $this->attach($root, $pc);
        $person = $this->person();
        $this->assign($root, $person);
        $this->operate($root, ['operation' => 'disband', 'note' => 'Пересборка'])->assertOk();
        $this->assertEquals(3, ThingAssignment::where('active', 1)->count());
        $this->assertEquals(1, ThingComposition::where('active', 1)->count());
        $this->getJson("{$this->url}/{$root->id}/compositions")->assertOk()->assertJsonPath('data.0.closing_note', 'Пересборка');
        $this->attach($root, $pc);
        $this->assertDatabaseCount('thing_compositions', 3);
        $this->assertDatabaseCount('things', 3);
        $this->getJson("{$this->url}/{$root->id}/history")->assertOk()->assertJsonPath('data.1.action', 'equip.detached');
    }

    public function test_stale_child_and_changed_assembly_abort_without_partial_assignments(): void
    {
        $root = $this->thing(['container_kind' => 'physical']);
        $child = $this->thing();
        $this->attach($root, $child);
        $snapshot = $this->revisions($root);
        $child->increment('revision');
        $person = $this->person();
        $this->operate($root, ['operation' => 'assign', 'expected_revisions' => $snapshot, 'user_id' => $person->id, 'responsibility' => 'use'])->assertConflict();
        $this->assertDatabaseCount('thing_assignments', 0);
        $snapshot = $this->revisions($root);
        unset($snapshot[$child->id]);
        $this->operate($root, ['operation' => 'assign', 'expected_revisions' => $snapshot, 'user_id' => $person->id, 'responsibility' => 'use'])->assertConflict();
        $this->operate($root, ['operation' => 'return', 'expected_revisions' => $this->revisions($root), 'revision' => 1])->assertConflict();
    }

    public function test_operation_permissions_are_separate_from_edit_and_self_assignment_is_forbidden(): void
    {
        $department = Department::factory()->create(['scope_id' => $this->scope->id]);
        $editor = $this->person();
        $membership = $this->scope->members()->where('user_id', $editor->id)->first();
        $membership->update(['department_id' => $department->id, 'permissions' => ['allow' => ['equip.update']]]);
        $thing = $this->thing(['department_id' => $department->id]);
        $this->actingAs($editor);
        $this->operate($thing, ['operation' => 'assign', 'expected_revisions' => $this->revisions($thing), 'user_id' => $editor->id, 'responsibility' => 'use'])->assertForbidden();
        $this->operate($thing, ['operation' => 'container', 'container_kind' => 'physical'])->assertForbidden();
        $this->patchJson("{$this->url}/{$thing->id}", ['revision' => 1, 'assignee_id' => $editor->id, 'container_kind' => 'physical'])->assertUnprocessable();
        $membership->update(['permissions' => ['allow' => ['equip.assign']]]);
        $this->assign($thing, $editor);
        $this->patchJson("{$this->url}/{$thing->id}", ['revision' => $thing->fresh()->revision, 'name' => 'Forbidden'])->assertForbidden();
        $membership->update(['permissions' => ['allow' => ['equip.assign'], 'deny' => ['equip.assign']]]);
        $this->operate($thing, ['operation' => 'return', 'expected_revisions' => $this->revisions($thing)])->assertForbidden();
        $this->assertDatabaseCount('thing_assignments', 1);
    }

    public function test_hidden_component_blocks_entire_issue_and_disband_atomically(): void
    {
        $own = Department::factory()->create(['scope_id' => $this->scope->id]);
        $other = Department::factory()->create(['scope_id' => $this->scope->id]);
        $root = $this->thing(['container_kind' => 'logical', 'department_id' => $own->id]);
        $visible = $this->thing(['department_id' => $own->id]);
        $hidden = $this->thing(['department_id' => $other->id]);
        $this->attach($root, $visible);
        $this->attach($root, $hidden);
        $person = $this->person();
        $this->scope->members()->where('user_id', $person->id)->update(['department_id' => $own->id, 'permissions' => ['allow' => ['equip.assign', 'equip.compose']]]);
        $this->actingAs($person);
        $this->getJson("{$this->url}/{$root->id}/operations")->assertOk()->assertJsonPath('data.can_assign', false)->assertJsonPath('data.hidden_components', true)->assertJsonCount(2, 'data.assembly');
        $this->operate($root, ['operation' => 'assign', 'expected_revisions' => $this->revisions($root), 'user_id' => $person->id, 'responsibility' => 'use'])->assertForbidden();
        $this->operate($root, ['operation' => 'disband'])->assertForbidden();
        $this->assertDatabaseCount('thing_assignments', 0);
        $this->assertEquals(2, ThingComposition::where('active', 1)->count());
    }

    public function test_scope_boundaries_invalid_recipients_and_observer_writes(): void
    {
        $root = $this->thing(['container_kind' => 'physical']);
        $outsider = User::factory()->create();
        $otherScope = Scope::factory()->create(['owner_id' => $outsider->id]);
        $foreign = Thing::factory()->create(['scope_id' => $otherScope->id]);
        $this->operate($root, ['operation' => 'attach', 'child_id' => $foreign->id, 'child_revision' => 1])->assertNotFound();
        foreach ([$outsider, $this->person(['status' => 'blocked']), $this->person(['type' => 'agent']), $this->person(['is_active' => false])] as $invalid) {
            $this->operate($root, ['operation' => 'assign', 'expected_revisions' => $this->revisions($root), 'user_id' => $invalid->id, 'responsibility' => 'use'])->assertUnprocessable();
        }
        $this->getJson("{$this->url}/{$foreign->id}/operations")->assertNotFound();
        $this->getJson("{$this->url}/{$foreign->id}/assignments")->assertNotFound();
        $this->getJson("{$this->url}/{$foreign->id}/compositions")->assertNotFound();
        $this->actingAs($outsider);
        $this->getJson("{$this->url}/{$root->id}/operations")->assertForbidden();
        $this->assertDatabaseCount('thing_assignments', 0);
    }

    public function test_suppliers_are_normalized_scoped_and_preserved_in_card_history(): void
    {
        $a = $this->postJson("{$this->url}/suppliers", ['name' => 'ООО   Ромашка'])->assertCreated()->json('data');
        $this->postJson("{$this->url}/suppliers", ['name' => '  ооо ромашка  '])->assertOk()->assertJsonPath('data.id', $a['id']);
        $thing = $this->thing();
        $this->patchJson("{$this->url}/{$thing->id}", ['revision' => 1, 'supplier_id' => $a['id']])->assertOk()->assertJsonPath('data.supplier_entry.id', $a['id']);
        $other = Scope::factory()->create();
        $foreign = ThingSupplier::create(['scope_id' => $other->id, 'name' => 'Secret', 'normalized_name' => 'secret']);
        $this->patchJson("{$this->url}/{$thing->id}", ['revision' => 2, 'supplier_id' => $foreign->id])->assertUnprocessable();
        $this->getJson("{$this->url}/suppliers")->assertJsonCount(1, 'data');
        $this->patchJson("{$this->url}/{$thing->id}", ['revision' => 2, 'supplier_id' => null])->assertOk()->assertJsonPath('data.supplier', null);
        $this->getJson("{$this->url}/{$thing->id}/history")->assertJsonPath('data.0.before.supplier', 'ООО Ромашка');
        $observer = $this->person();
        $this->actingAs($observer);
        $this->postJson("{$this->url}/suppliers", ['name' => 'Forbidden'])->assertForbidden();
    }

    public function test_supplier_backfill_preserves_old_strings_and_deduplicates_ids(): void
    {
        $migration = require database_path('migrations/2026_10_19_000116_add_equip_assignments_composition_and_suppliers.php');
        $a = $this->thing(['supplier' => 'ООО   Ромашка']);
        $b = $this->thing(['supplier' => 'ооо ромашка']);
        $migration->down();
        $migration->up();
        $this->assertDatabaseCount('thing_suppliers', 1);
        $this->assertNotNull($a->fresh()->supplier_id);
        $this->assertSame($a->fresh()->supplier_id, $b->fresh()->supplier_id);
        $this->assertSame('ООО   Ромашка', $a->fresh()->supplier);
    }

    public function test_compose_permission_alone_can_attach_detach_and_change_empty_container(): void
    {
        $department = Department::factory()->create(['scope_id' => $this->scope->id]);
        $person = $this->person();
        $this->scope->members()->where('user_id', $person->id)->update(['department_id' => $department->id, 'permissions' => ['allow' => ['equip.compose']]]);
        $root = $this->thing(['department_id' => $department->id]);
        $child = $this->thing(['department_id' => $department->id]);
        $this->actingAs($person);
        $this->operate($root, ['operation' => 'container', 'container_kind' => 'physical'])->assertOk();
        $this->attach($root, $child);
        $relation = ThingComposition::firstOrFail();
        $this->operate($child, ['operation' => 'detach', 'relation_id' => $relation->id])->assertOk();
        $this->operate($root, ['operation' => 'container', 'container_kind' => 'none'])->assertOk();
        $this->assertNotNull($relation->fresh()->ended_at);
        $this->assertDatabaseCount('thing_assignments', 0);
    }
}
