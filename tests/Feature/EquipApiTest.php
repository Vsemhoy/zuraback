<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\FilerFile;
use App\Models\Scope;
use App\Models\Thing;
use App\Models\ThingAssignment;
use App\Models\ThingSupplier;
use App\Models\ThingType;
use App\Models\User;
use Database\Seeders\EquipTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EquipApiTest extends TestCase
{
    use RefreshDatabase;

    private const HEADERS = ['X-App-Request' => 'Zuratax', 'Accept' => 'application/json'];

    private function workspace(): array
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $type = ThingType::factory()->create(['scope_id' => $scope->id, 'schema' => [['key' => 'ram', 'label' => 'RAM', 'type' => 'number']]]);
        $this->actingAs($owner)->withHeaders(self::HEADERS);

        return [$scope, $type, $owner, "/api/scopes/{$scope->id}/equip"];
    }

    private function payload(ThingType $type): array
    {
        return ['name' => 'Рабочий компьютер', 'thing_type_id' => $type->id, 'registration_basis' => 'Счёт 184 от 19.10.2026', 'characteristics' => ['ram' => 16]];
    }

    #[DataProvider('containerKinds')]
    public function test_equipment_can_be_created_with_its_container_kind(string $kind): void
    {
        [$scope, $type, $owner, $url] = $this->workspace();

        $id = $this->postJson($url, [...$this->payload($type), 'container_kind' => $kind])
            ->assertCreated()->assertJsonPath('data.container_kind', $kind)->assertJsonPath('data.revision', 1)->json('data.id');

        $this->assertDatabaseHas('things', ['id' => $id, 'container_kind' => $kind, 'created_by' => $owner->id]);
        $this->getJson("$url/$id/history")->assertOk()->assertJsonPath('data.0.after.container_kind', $kind);
        $this->assertDatabaseCount('activity_logs', 1);
        $this->patchJson("$url/$id", ['revision' => 1, 'container_kind' => 'none'])->assertUnprocessable()->assertJsonValidationErrors('container_kind');
        $this->assertDatabaseHas('things', ['id' => $id, 'container_kind' => $kind, 'revision' => 1]);
    }

    public static function containerKinds(): array
    {
        return ['computer' => ['physical'], 'workplace' => ['logical']];
    }

    public function test_container_creation_requires_compose_but_standalone_creation_does_not(): void
    {
        [$scope, $type, , $url] = $this->workspace();
        $member = User::factory()->create();
        $department = Department::factory()->create(['scope_id' => $scope->id]);
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'department_id' => $department->id, 'permissions' => ['allow' => ['equip.create']]]);
        $this->actingAs($member);

        $this->postJson($url, [...$this->payload($type), 'container_kind' => 'physical'])->assertForbidden();
        $this->assertDatabaseCount('things', 0);
        $this->postJson($url, [...$this->payload($type), 'container_kind' => 'none'])->assertCreated()->assertJsonPath('data.container_kind', 'none');

        $this->assertDatabaseHas('things', ['created_by' => $member->id, 'container_kind' => 'none']);
        $this->assertDatabaseCount('activity_logs', 1);
    }

    public function test_compose_without_create_does_not_allow_new_container(): void
    {
        [$scope, $type, , $url] = $this->workspace();
        $member = User::factory()->create();
        $department = Department::factory()->create(['scope_id' => $scope->id]);
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'department_id' => $department->id, 'permissions' => ['allow' => ['equip.compose']]]);

        $this->actingAs($member)->postJson($url, [...$this->payload($type), 'container_kind' => 'logical'])->assertForbidden();

        $this->assertDatabaseCount('things', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_container_creation_rejects_an_invalid_kind(): void
    {
        [$scope, $type, , $url] = $this->workspace();

        $this->postJson($url, [...$this->payload($type), 'container_kind' => 'virtual'])->assertUnprocessable()->assertJsonValidationErrors('container_kind');

        $this->assertDatabaseCount('things', 0);
    }

    public function test_system_workplace_type_is_available_for_logical_container_creation(): void
    {
        [$scope, , , $url] = $this->workspace();
        $type = ThingType::query()->where('code', 'workplace')->whereNull('scope_id')->firstOrFail();

        $id = $this->postJson($url, [...$this->payload($type), 'name' => 'Рабочее место инженера', 'characteristics' => [], 'container_kind' => 'logical'])
            ->assertCreated()->assertJsonPath('data.type.name', 'Рабочее место')->assertJsonPath('data.container_kind', 'logical')->json('data.id');

        $this->assertDatabaseHas('things', ['id' => $id, 'thing_type_id' => $type->id, 'container_kind' => 'logical']);
    }

    public function test_department_codes_are_returned_and_sort_as_displayed_with_legacy_fallback(): void
    {
        [$scope, $type, $owner, $url] = $this->workspace();
        $firstDepartment = Department::factory()->create(['scope_id' => $scope->id, 'name' => 'Zulu department', 'code' => 'ADM']);
        $lastDepartment = Department::factory()->create(['scope_id' => $scope->id, 'name' => 'Alpha department', 'code' => 'ZED']);
        $legacyDepartment = Department::factory()->create(['scope_id' => $scope->id, 'name' => 'Middle department']);
        $first = Thing::factory()->create(['scope_id' => $scope->id, 'thing_type_id' => $type->id, 'department_id' => $firstDepartment->id]);
        $last = Thing::factory()->create(['scope_id' => $scope->id, 'thing_type_id' => $type->id, 'department_id' => $lastDepartment->id]);
        $middle = Thing::factory()->create(['scope_id' => $scope->id, 'thing_type_id' => $type->id, 'department_id' => $legacyDepartment->id]);

        $this->getJson("$url?sort=department&direction=asc")->assertOk()->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.department.code', 'ADM')->assertJsonPath('data.0.department.name', 'Zulu department')
            ->assertJsonPath('data.1.id', $middle->id)->assertJsonPath('data.2.id', $last->id);
        $this->getJson("$url?sort=department&direction=desc")->assertOk()->assertJsonPath('data.0.id', $last->id);
        $this->getJson("$url/{$first->id}")->assertOk()->assertJsonPath('data.department.code', 'ADM');
        $this->getJson("$url/options")->assertOk()->assertJsonFragment(['code' => 'ADM']);
    }

    public function test_registration_and_revision_preserve_wms_strings_and_history(): void
    {
        [$scope, $type, $owner, $url] = $this->workspace();
        $id = $this->postJson($url, [...$this->payload($type), 'wms_product_id' => '00042', 'wms_inventory_unit' => '00001'])
            ->assertCreated()->assertJsonPath('data.wms_inventory_unit', '00001')->json('data.id');
        $this->assertDatabaseHas('things', ['id' => $id, 'scope_id' => $scope->id, 'wms_product_id' => '00042', 'created_by' => $owner->id]);
        $this->patchJson("$url/$id", ['revision' => 1, 'registration_basis' => 'Уточнённый счёт 185'])->assertOk()->assertJsonPath('data.revision', 2);
        $this->getJson("$url/$id/history")->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.before.registration_basis', 'Счёт 184 от 19.10.2026')
            ->assertJsonPath('data.0.after.registration_basis', 'Уточнённый счёт 185');
        $this->patchJson("$url/$id", ['revision' => 1, 'name' => 'Stale'])->assertConflict();
        $this->assertDatabaseMissing('things', ['id' => $id, 'name' => 'Stale']);
        $this->assertDatabaseCount('activity_logs', 2);
    }

    public function test_wms_product_repeats_unit_is_unique_only_within_scope_and_null_is_allowed(): void
    {
        [$scope, $type, , $url] = $this->workspace();
        Thing::factory()->create(['scope_id' => $scope->id, 'thing_type_id' => $type->id, 'wms_product_id' => '00042', 'wms_inventory_unit' => '00001']);
        $this->postJson($url, [...$this->payload($type), 'wms_product_id' => '00042', 'wms_inventory_unit' => '00002'])->assertCreated();
        $this->postJson($url, [...$this->payload($type), 'wms_inventory_unit' => '00001'])->assertUnprocessable()->assertJsonValidationErrors('wms_inventory_unit');
        $this->postJson($url, [...$this->payload($type), 'wms_inventory_unit' => ''])->assertCreated()->assertJsonPath('data.wms_inventory_unit', null);
        $this->postJson($url, $this->payload($type))->assertCreated();
        [$other, $otherType, , $otherUrl] = $this->workspace();
        $this->postJson($otherUrl, [...$this->payload($otherType), 'wms_inventory_unit' => '00001'])->assertCreated();
        $this->assertDatabaseCount('things', 5);
    }

    public function test_required_basis_characteristics_and_protected_fields_are_validated_422(): void
    {
        [, $type, , $url] = $this->workspace();
        $this->postJson($url, [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'thing_type_id', 'registration_basis']);
        $this->postJson($url, [...$this->payload($type), 'characteristics' => ['ram' => 'sixteen']])->assertUnprocessable()->assertJsonValidationErrors('characteristics.ram');
        $this->postJson($url, [...$this->payload($type), 'characteristics' => ['unknown' => 'x']])->assertUnprocessable()->assertJsonValidationErrors('characteristics.unknown');
        $this->postJson($url, [...$this->payload($type), 'lifecycle' => 'written_off'])->assertUnprocessable()->assertJsonValidationErrors('lifecycle');
        $this->assertDatabaseCount('things', 0);
        $this->assertDatabaseCount('activity_logs', 0);
    }

    public function test_member_can_read_own_department_but_cannot_create_edit_or_see_other_department(): void
    {
        [$scope, $type, , $url] = $this->workspace();
        $member = User::factory()->create();
        $department = Department::factory()->create(['scope_id' => $scope->id]);
        $other = Department::factory()->create(['scope_id' => $scope->id]);
        $scope->members()->create(['user_id' => $member->id, 'department_id' => $department->id, 'role' => 'member']);
        $thing = Thing::factory()->create(['scope_id' => $scope->id, 'thing_type_id' => $type->id, 'department_id' => $department->id]);
        $hidden = Thing::factory()->create(['scope_id' => $scope->id, 'thing_type_id' => $type->id, 'department_id' => $other->id]);
        Thing::factory()->create(['scope_id' => $scope->id, 'thing_type_id' => $type->id]);
        $this->actingAs($member);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $thing->id);
        $this->getJson("$url/{$hidden->id}")->assertNotFound();
        $this->getJson("$url/{$hidden->id}/history")->assertNotFound();
        $this->postJson($url, $this->payload($type))->assertForbidden();
        $this->patchJson("$url/{$thing->id}", ['revision' => 1, 'name' => 'Forbidden'])->assertForbidden();
        $this->getJson("$url/options")->assertJsonPath('data.permissions.create', false)->assertJsonCount(1, 'data.departments');
        $this->assertDatabaseMissing('things', ['name' => 'Forbidden']);
    }

    public function test_granted_editor_cannot_move_to_another_department_or_change_owner_and_condition(): void
    {
        [$scope, $type, , $url] = $this->workspace();
        $member = User::factory()->create();
        $department = Department::factory()->create(['scope_id' => $scope->id]);
        $other = Department::factory()->create(['scope_id' => $scope->id]);
        $membership = $scope->members()->create(['user_id' => $member->id, 'department_id' => $department->id, 'role' => 'member', 'permissions' => ['allow' => ['equip.create', 'equip.update']]]);
        $this->actingAs($member);
        $id = $this->postJson($url, $this->payload($type))->assertCreated()->assertJsonPath('data.department_id', $department->id)->json('data.id');
        $this->patchJson("$url/$id", ['revision' => 1, 'name' => 'Allowed'])->assertOk();
        $this->patchJson("$url/$id", ['revision' => 2, 'department_id' => $other->id])->assertForbidden();
        $this->patchJson("$url/$id", ['revision' => 2, 'condition' => 'broken'])->assertForbidden();
        $this->patchJson("$url/$id", ['revision' => 2, 'owner_kind' => 'external', 'owner_name' => 'Someone'])->assertForbidden();
        $this->postJson($url, [...$this->payload($type), 'department_id' => $other->id])->assertForbidden();
        $this->assertDatabaseHas('things', ['id' => $id, 'revision' => 2, 'condition' => 'working', 'owner_kind' => 'organization']);
        $membership->update(['permissions' => ['allow' => ['equip.update', 'equip.state', 'equip.ownership']]]);
        $this->patchJson("$url/$id", ['revision' => 2, 'condition' => 'broken', 'owner_kind' => 'employee', 'owner_id' => $member->id])->assertOk();
        $this->assertDatabaseHas('things', ['id' => $id, 'owner_id' => $member->id, 'condition' => 'broken']);
    }

    public function test_no_department_and_explicit_deny_do_not_leak_equipment(): void
    {
        [$scope, $type, , $url] = $this->workspace();
        $member = User::factory()->create();
        $membership = $scope->members()->create(['user_id' => $member->id, 'role' => 'observer']);
        $thing = Thing::factory()->create(['scope_id' => $scope->id, 'thing_type_id' => $type->id]);
        $this->actingAs($member);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("$url/{$thing->id}")->assertNotFound();
        $membership->update(['permissions' => ['allow' => ['equip.access_all']]]);
        $this->getJson($url)->assertJsonCount(1, 'data');
        $membership->update(['role' => 'admin', 'permissions' => ['deny' => ['equip.view']]]);
        $this->getJson($url)->assertForbidden();
    }

    public function test_foreign_scope_objects_and_references_are_rejected(): void
    {
        [$scope, $type, , $url] = $this->workspace();
        $foreign = Thing::factory()->create();
        $foreignDepartment = Department::factory()->create();
        $foreignType = ThingType::factory()->create();
        $foreignUser = User::factory()->create();
        $this->getJson("$url/{$foreign->id}")->assertNotFound();
        $this->patchJson("$url/{$foreign->id}", ['revision' => 1, 'name' => 'Foreign'])->assertNotFound();
        $this->postJson($url, [...$this->payload($type), 'department_id' => $foreignDepartment->id])->assertUnprocessable();
        $this->postJson($url, [...$this->payload($type), 'thing_type_id' => $foreignType->id])->assertUnprocessable();
        $this->postJson($url, [...$this->payload($type), 'owner_kind' => 'employee', 'owner_id' => $foreignUser->id])->assertUnprocessable();
        $this->assertDatabaseMissing('things', ['name' => 'Foreign']);
        $this->assertDatabaseCount('things', 1);
    }

    public function test_type_seeder_is_idempotent_and_custom_types_are_scoped(): void
    {
        [$scope, , , $url] = $this->workspace();
        $this->seed(EquipTypeSeeder::class);
        $this->seed(EquipTypeSeeder::class);
        $this->assertDatabaseCount('thing_types', 12);
        $id = $this->postJson("$url/types", ['name' => 'Тестер кабеля', 'schema' => [['key' => 'ports', 'label' => 'Порты', 'type' => 'number']]])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('thing_types', ['id' => $id, 'scope_id' => $scope->id]);
        $this->postJson("$url/types", ['name' => 'Bad', 'schema' => [['key' => 'bad.key', 'label' => 'X', 'type' => 'html']]])->assertUnprocessable();
        $this->getJson("$url/options")->assertJsonCount(13, 'data.types');
    }

    public function test_search_filters_and_nonempty_department_delete(): void
    {
        [$scope, $type, , $url] = $this->workspace();
        $department = Department::factory()->create(['scope_id' => $scope->id]);
        $thing = Thing::factory()->create(['scope_id' => $scope->id, 'thing_type_id' => $type->id, 'department_id' => $department->id, 'serial_number' => 'SN-TEST', 'condition' => 'broken']);
        Thing::factory()->create(['scope_id' => $scope->id, 'thing_type_id' => $type->id]);
        $this->getJson("$url?q=SN-TEST&condition=broken&department_id={$department->id}&thing_type_id={$type->id}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $thing->id);
        $this->deleteJson("/api/scopes/{$scope->id}/departments/{$department->id}")->assertUnprocessable();
        $this->assertModelExists($department);
    }

    public function test_equipment_files_follow_equipment_permissions(): void
    {
        Storage::fake('filer');
        config(['filer.reserve_bytes' => 0]);
        [$scope, $type, , $url] = $this->workspace();
        $department = Department::factory()->create(['scope_id' => $scope->id]);
        $thing = Thing::factory()->create(['scope_id' => $scope->id, 'thing_type_id' => $type->id, 'department_id' => $department->id]);
        $files = "/api/scopes/{$scope->id}/files";
        $fileId = $this->postJson($files, ['file' => UploadedFile::fake()->create('invoice.txt', 1, 'text/plain'), 'category' => 'thing', 'subject_type' => 'thing', 'subject_id' => $thing->id, 'visibility' => 'scope'])->assertCreated()->json('data.id');
        $member = User::factory()->create();
        $membership = $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'department_id' => $department->id]);
        $this->actingAs($member)->getJson("$files?subject_type=thing&subject_id={$thing->id}")->assertJsonCount(1, 'data');
        $this->getJson("$files/$fileId/download")->assertOk();
        $this->postJson($files, ['file' => UploadedFile::fake()->create('bad.txt', 1), 'category' => 'thing', 'subject_type' => 'thing', 'subject_id' => $thing->id, 'visibility' => 'scope'])->assertNotFound();
        $membership->update(['department_id' => null]);
        $this->getJson("$files?subject_type=thing&subject_id={$thing->id}")->assertJsonCount(0, 'data');
        $this->getJson("$files/$fileId/download")->assertNotFound();
        $this->assertDatabaseCount('filer_files', 1);
    }

    public function test_guests_and_outsiders_cannot_open_equip(): void
    {
        $scope = Scope::factory()->create();
        $this->withHeaders(self::HEADERS)->getJson("/api/scopes/{$scope->id}/equip")->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson("/api/scopes/{$scope->id}/equip")->assertForbidden();
    }

    public function test_editor_uploads_photos_without_task_write_permissions(): void
    {
        Storage::fake('filer');
        config(['filer.reserve_bytes' => 0]);
        [$scope, $type, , $url] = $this->workspace();
        $department = Department::factory()->create(['scope_id' => $scope->id]);
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'observer', 'department_id' => $department->id, 'permissions' => ['allow' => ['equip.update']]]);
        $thing = Thing::factory()->create(['scope_id' => $scope->id, 'thing_type_id' => $type->id, 'department_id' => $department->id]);
        $files = "/api/scopes/{$scope->id}/files";
        $this->actingAs($member);
        $fileId = $this->postJson($files, ['file' => UploadedFile::fake()->image('monitor.jpg', 40, 40), 'photo' => true, 'category' => 'thing', 'subject_type' => 'thing', 'subject_id' => $thing->id, 'visibility' => 'scope'])->assertCreated()->assertJsonPath('data.mime', 'image/webp')->json('data.id');
        $this->getJson("$files/$fileId/image")->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->postJson($files, ['file' => UploadedFile::fake()->create('fake.jpg', 1, 'text/plain'), 'photo' => true, 'category' => 'thing', 'subject_type' => 'thing', 'subject_id' => $thing->id, 'visibility' => 'scope'])->assertUnprocessable();
        $this->assertDatabaseCount('filer_files', 1);
    }

    public function test_equipment_type_and_owner_changes_revalidate_characteristics_and_references(): void
    {
        [$scope, $type, , $url] = $this->workspace();
        $thing = Thing::factory()->create(['scope_id' => $scope->id, 'thing_type_id' => $type->id, 'characteristics' => ['ram' => 32]]);
        $newType = ThingType::factory()->create(['scope_id' => $scope->id, 'schema' => []]);
        $this->patchJson("$url/{$thing->id}", ['revision' => 1, 'thing_type_id' => $newType->id])->assertUnprocessable()->assertJsonValidationErrors('characteristics.ram');
        $this->patchJson("$url/{$thing->id}", ['revision' => 1, 'thing_type_id' => $newType->id, 'characteristics' => []])->assertOk();
        $this->patchJson("$url/{$thing->id}", ['revision' => 2, 'owner_kind' => 'external'])->assertUnprocessable()->assertJsonValidationErrors('owner_name');
        $this->assertDatabaseHas('things', ['id' => $thing->id, 'revision' => 2, 'owner_kind' => 'organization']);
    }

    public function test_registry_sorting_is_global_numeric_stable_and_validated(): void
    {
        [$scope, $type, , $url] = $this->workspace();
        for ($i = 35; $i >= 1; $i--) {
            Thing::factory()->create(['scope_id' => $scope->id, 'thing_type_id' => $type->id, 'name' => sprintf('Item %02d', $i), 'purchase_cost' => $i * 10]);
        }
        Thing::factory()->create(['name' => 'Item 00']);
        $first = $this->getJson("$url?sort=name&direction=asc")->assertOk()->assertJsonPath('meta.total', 35)->assertJsonPath('data.0.name', 'Item 01')->assertJsonPath('data.29.name', 'Item 30')->json('data');
        $second = $this->getJson("$url?sort=name&direction=asc&page=2")->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('data.0.name', 'Item 31')->json('data');
        $this->assertEmpty(array_intersect(array_column($first, 'id'), array_column($second, 'id')));
        $this->getJson("$url?sort=purchase_cost&direction=desc")->assertOk()->assertJsonPath('data.0.purchase_cost', '350.00')->assertJsonPath('data.29.purchase_cost', '60.00');
        $this->getJson("$url?sort=invalid")->assertUnprocessable()->assertJsonValidationErrors('sort');
        $this->getJson("$url?direction=invalid")->assertUnprocessable()->assertJsonValidationErrors('direction');
        foreach (['condition', 'suitability', 'container_kind', 'owner', 'supplier', 'assignee', 'type', 'department', 'created_at', 'updated_at'] as $sort) {
            $this->getJson("$url?sort=$sort&direction=asc")->assertOk()->assertJsonPath('meta.total', 35);
        }
    }

    public function test_registry_sorts_related_names_instead_of_ids(): void
    {
        [$scope, $type, $owner, $url] = $this->workspace();
        $aDepartment = Department::factory()->create(['scope_id' => $scope->id, 'name' => 'Alpha']);
        $zDepartment = Department::factory()->create(['scope_id' => $scope->id, 'name' => 'Zulu']);
        $a = Thing::factory()->create(['scope_id' => $scope->id, 'thing_type_id' => $type->id, 'department_id' => $aDepartment->id, 'owner_kind' => 'external', 'owner_name' => 'Alpha']);
        $z = Thing::factory()->create(['scope_id' => $scope->id, 'thing_type_id' => $type->id, 'department_id' => $zDepartment->id, 'owner_kind' => 'external', 'owner_name' => 'Zulu']);
        foreach ([[$a, 'Alpha'], [$z, 'Zulu']] as [$thing, $name]) {
            $supplier = ThingSupplier::create(['scope_id' => $scope->id, 'name' => $name, 'normalized_name' => strtolower($name)]);
            $thing->update(['supplier_id' => $supplier->id]);
            ThingAssignment::create(['scope_id' => $scope->id, 'thing_id' => $thing->id, 'user_id' => $owner->id, 'user_name' => $name, 'responsibility' => 'use', 'started_at' => now(), 'active' => 1, 'batch_root_id' => $thing->id]);
        }
        foreach (['department', 'supplier', 'assignee', 'owner'] as $sort) {
            $this->getJson("$url?sort=$sort&direction=asc")->assertOk()->assertJsonPath('data.0.id', $a->id);
            $this->getJson("$url?sort=$sort&direction=desc")->assertOk()->assertJsonPath('data.0.id', $z->id);
        }
    }

    public function test_cover_is_first_visible_gallery_photo_and_respects_all_file_attachments(): void
    {
        [$scope, $type, $owner, $url] = $this->workspace();
        $department = Department::factory()->create(['scope_id' => $scope->id]);
        $hiddenDepartment = Department::factory()->create(['scope_id' => $scope->id]);
        $thing = Thing::factory()->create(['scope_id' => $scope->id, 'thing_type_id' => $type->id, 'department_id' => $department->id]);
        $hidden = Thing::factory()->create(['scope_id' => $scope->id, 'thing_type_id' => $type->id, 'department_id' => $hiddenDepartment->id]);
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'department_id' => $department->id, 'role' => 'member']);
        $files = [];
        foreach (['first', 'second', 'restricted', 'private', 'document'] as $name) {
            $file = FilerFile::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'name' => $name, 'mime' => $name === 'document' ? 'application/pdf' : 'image/webp', 'visibility' => $name === 'private' ? 'private' : 'scope']);
            $file->attachments()->create(['subject_type' => 'thing', 'subject_id' => $thing->id]);
            $files[$name] = $file;
        }
        $files['restricted']->attachments()->create(['subject_type' => 'thing', 'subject_id' => $hidden->id]);
        $this->actingAs($member);
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.cover_photo_id', $files['second']->id);
        $files['second']->attachments()->delete();
        $files['second']->delete();
        $this->getJson($url)->assertJsonPath('data.0.cover_photo_id', $files['first']->id);
        $files['first']->attachments()->delete();
        $files['first']->delete();
        $this->getJson($url)->assertJsonPath('data.0.cover_photo_id', null);
    }
}
