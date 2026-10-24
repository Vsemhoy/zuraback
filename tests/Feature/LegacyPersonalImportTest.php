<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\Fact;
use App\Models\LegacyImportRecord;
use App\Models\Scope;
use App\Models\User;
use App\Services\LegacyPersonalImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class LegacyPersonalImportTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id, 'is_private' => true]);
        $legacyOwner = (string) Str::ulid();
        $row = fn (array $data): array => ['id' => Str::lower((string) Str::ulid()), 'user_id' => $legacyOwner,
            'created_at' => '2025-02-03 10:00:00', 'updated_at' => '2025-02-04 11:00:00', 'deleted_at' => null, ...$data];
        $first = $row(['name' => 'Контакт А', 'group' => 'Коллеги', 'met_at' => '2020-01-01', 'met_precision' => 'year', 'avatar' => 'legacy-image.png']);
        $second = $row(['name' => 'Контакт Б']);
        $snapshot = ['schema' => 1, 'source' => LegacyPersonalImportService::SOURCE, 'owner_id' => $legacyOwner,
            'owner_hash' => hash('sha256', Str::lower($owner->email)), 'contacts' => [$first, $second],
            'notes' => [$row(['contact_id' => $first['id'], 'title' => 'Разговор', 'body_md' => "# Встреча\n\nТекст", 'occurred_at' => '2025-01-01 12:30:00', 'tasker_task_id' => (string) Str::ulid()])],
            'facts' => [$row(['label' => 'Личный факт', 'value' => 'PRIVATE-SECRET-TEST', 'format' => 'text', 'display_mode' => 'plain', 'is_sensitive' => true, 'search_keywords' => '["поиск"]'])],
            'details' => [$row(['contact_id' => $first['id'], 'label' => 'Телефон', 'value' => '+70000000000', 'kind' => 'phone'])],
            'relations' => [$row(['contact_a_id' => $first['id'], 'contact_b_id' => $second['id'], 'kind' => 'colleague', 'note' => 'Связь', 'valid_from' => '2020-01-01'])]];

        return [$scope, $owner, $snapshot];
    }

    public function test_dry_run_plans_all_records_without_writing_anything(): void
    {
        [$scope, , $snapshot] = $this->fixture();

        $result = app(LegacyPersonalImportService::class)->import($scope, $snapshot);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(7, $result['planned']);
        $this->assertSame(0, $result['created']);
        foreach (['contacts', 'contact_notes', 'facts', 'entity_links', 'legacy_import_records'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_import_preserves_markdown_dates_links_privacy_and_encrypted_source_archive(): void
    {
        [$scope, $owner, $snapshot] = $this->fixture();
        $userCount = User::count();

        $result = app(LegacyPersonalImportService::class)->import($scope, $snapshot, true);

        $this->assertSame(7, $result['created']);
        $this->assertDatabaseHas('contacts', ['id' => $snapshot['contacts'][0]['id'], 'scope_id' => $scope->id, 'created_by' => $owner->id, 'user_id' => null, 'project_id' => null, 'visibility' => 'private', 'met_at' => null, 'created_at' => '2025-02-03 10:00:00']);
        $this->assertStringContainsString('точность: year', Contact::findOrFail($snapshot['contacts'][0]['id'])->note_md);
        $this->assertDatabaseHas('contact_notes', ['contact_id' => $snapshot['contacts'][0]['id'], 'content' => "# Встреча\n\nТекст", 'occurred_at' => '2025-01-01 12:30:00']);
        $this->assertDatabaseHas('facts', ['id' => $snapshot['facts'][0]['id'], 'value' => 'PRIVATE-SECRET-TEST', 'visibility' => 'private', 'is_sensitive' => true]);
        $this->assertDatabaseHas('facts', ['id' => $snapshot['details'][0]['id'], 'value' => '+70000000000', 'visibility' => 'private', 'is_sensitive' => true]);
        $this->assertDatabaseHas('entity_links', ['source_id' => $snapshot['contacts'][0]['id'], 'target_type' => 'fact', 'target_id' => $snapshot['details'][0]['id']]);
        $this->assertDatabaseHas('entity_links', ['source_id' => $snapshot['contacts'][0]['id'], 'target_type' => 'contact', 'target_id' => $snapshot['contacts'][1]['id']]);
        $archive = LegacyImportRecord::where('source_type', 'fact')->sole();
        $this->assertSame('PRIVATE-SECRET-TEST', $archive->snapshot['value']);
        $this->assertStringNotContainsString('PRIVATE-SECRET-TEST', $archive->getRawOriginal('snapshot'));
        $this->assertArrayNotHasKey('snapshot', $archive->toArray());
        $this->assertSame($userCount, User::count());
    }

    public function test_repeat_import_skips_edited_and_soft_deleted_targets_without_resurrection(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $importer = app(LegacyPersonalImportService::class);
        $importer->import($scope, $snapshot, true);
        Fact::findOrFail($snapshot['facts'][0]['id'])->update(['value' => 'new value']);
        ContactNote::findOrFail($snapshot['notes'][0]['id'])->delete();

        $result = $importer->import($scope, $snapshot, true);

        $this->assertSame(0, $result['created']);
        $this->assertSame(7, array_sum($result['counts']['skip']));
        $this->assertDatabaseCount('legacy_import_records', 7);
        $this->assertDatabaseHas('facts', ['id' => $snapshot['facts'][0]['id'], 'value' => 'new value']);
        $this->assertSoftDeleted('contact_notes', ['id' => $snapshot['notes'][0]['id']]);
    }

    public function test_rejects_changed_sources_without_overwriting_targets(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $importer = app(LegacyPersonalImportService::class);
        $importer->import($scope, $snapshot, true);
        $snapshot['facts'][0]['value'] = 'changed source';

        try {
            $importer->import($scope, $snapshot, true);
            $this->fail('Changed source must be reviewed.');
        } catch (LogicException) {
            $this->assertDatabaseCount('legacy_import_records', 7);
            $this->assertDatabaseHas('facts', ['value' => 'PRIVATE-SECRET-TEST']);
        }
    }

    public function test_rejects_a_source_with_another_owner_and_leaves_no_partial_import(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $snapshot['notes'][0]['user_id'] = (string) Str::ulid();

        try {
            app(LegacyPersonalImportService::class)->import($scope, $snapshot, true);
            $this->fail('Mixed owners must be refused.');
        } catch (LogicException) {
            $this->assertDatabaseCount('contacts', 0);
            $this->assertDatabaseCount('legacy_import_records', 0);
        }
    }

    public function test_rejects_an_unmapped_existing_target_even_in_another_scope(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $existing = Contact::factory()->create(['id' => $snapshot['contacts'][0]['id']]);

        try {
            app(LegacyPersonalImportService::class)->import($scope, $snapshot, true);
            $this->fail('Unmapped IDs cannot be overwritten.');
        } catch (LogicException) {
            $this->assertModelExists($existing);
            $this->assertDatabaseCount('contacts', 1);
            $this->assertDatabaseCount('legacy_import_records', 0);
        }
    }

    public function test_rejects_public_scope_or_mismatched_owner_identity(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $scope->update(['is_private' => false]);

        try {
            app(LegacyPersonalImportService::class)->import($scope, $snapshot, true);
            $this->fail('Public workspace must be refused.');
        } catch (LogicException) {
            $this->assertDatabaseCount('contacts', 0);
        }
        $scope->update(['is_private' => true]);
        $snapshot['owner_hash'] = str_repeat('0', 64);
        $this->expectException(LogicException::class);
        app(LegacyPersonalImportService::class)->import($scope, $snapshot, true);
    }

    public function test_a_failure_while_archiving_rolls_back_the_entire_batch(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $event = 'eloquent.creating: '.LegacyImportRecord::class;
        Event::listen($event, function (): void {
            throw new RuntimeException('Synthetic failure');
        });

        try {
            app(LegacyPersonalImportService::class)->import($scope, $snapshot, true);
            $this->fail('Archive failure must abort.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('contacts', 0);
            $this->assertDatabaseCount('legacy_import_records', 0);
        } finally {
            Event::forget($event);
        }
    }

    public function test_imported_records_and_links_remain_hidden_from_other_members_404(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        app(LegacyPersonalImportService::class)->import($scope, $snapshot, true);
        $member = User::factory()->create();
        $scope->members()->create(['user_id' => $member->id, 'role' => 'member', 'project_access_mode' => 'all', 'is_active' => true]);
        $base = "/api/scopes/{$scope->id}";

        $this->actingAs($member)->withHeaders(['X-App-Request' => 'Zuratax']);

        $this->getJson($base.'/contacts')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.'/facts')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($base.'/contacts/'.$snapshot['contacts'][0]['id'])->assertNotFound();
        $this->getJson($base.'/links')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_missing_contact_reference_prevents_the_whole_import(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $snapshot['notes'][0]['contact_id'] = (string) Str::ulid();

        try {
            app(LegacyPersonalImportService::class)->import($scope, $snapshot, true);
            $this->fail('Dangling contact references must be refused.');
        } catch (LogicException) {
            $this->assertDatabaseCount('contacts', 0);
            $this->assertDatabaseCount('legacy_import_records', 0);
        }
    }

    public function test_cli_requires_explicit_checksum_and_defaults_to_dry_run(): void
    {
        [$scope, , $snapshot] = $this->fixture();
        $file = tempnam(sys_get_temp_dir(), 'legacy-test-');
        $bytes = json_encode($snapshot, JSON_THROW_ON_ERROR);
        file_put_contents($file, $bytes);
        chmod($file, 0600);

        try {
            $this->artisan('legacy:import-personal', ['snapshot' => $file, '--scope' => $scope->id])->assertSuccessful();
            $this->assertDatabaseCount('contacts', 0);
            $this->artisan('legacy:import-personal', ['snapshot' => $file, '--scope' => $scope->id, '--commit' => true])->assertFailed();
            $this->assertDatabaseCount('contacts', 0);
            $this->artisan('legacy:import-personal', ['snapshot' => $file, '--scope' => $scope->id, '--commit' => true, '--expect-sha256' => str_repeat('0', 64)])->assertFailed();
            $this->assertDatabaseCount('contacts', 0);
            $this->artisan('legacy:import-personal', ['snapshot' => $file, '--scope' => $scope->id, '--commit' => true, '--expect-sha256' => hash('sha256', $bytes)])->assertSuccessful();
            $this->assertDatabaseCount('contacts', 2);
        } finally {
            unlink($file);
        }
    }
}
