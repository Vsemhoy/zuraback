<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Contact;
use App\Models\Diagram;
use App\Models\EntityLink;
use App\Models\Fact;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Scope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FactorContactorTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(string $role = 'member'): array
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $scope->members()->create(['user_id' => $member->id, 'role' => $role, 'project_access_mode' => 'all', 'is_active' => true, 'joined_at' => now()]);
        $this->actingAs($owner)->withHeaders(['X-App-Request' => 'Zuratax']);

        return [$owner, $member, $scope, "/api/scopes/{$scope->id}"];
    }

    public function test_migration_preserves_existing_scope_visibility_and_new_database_default_is_private(): void
    {
        [$owner, , $scope] = $this->workspace();
        $migration = require database_path('migrations/2026_10_24_192148_add_privacy_and_revision_to_facts_table.php');
        $migration->down();
        $legacyId = (string) Str::ulid();
        DB::table('facts')->insert(['id' => $legacyId, 'scope_id' => $scope->id, 'created_by' => $owner->id, 'label' => 'legacy', 'value' => 'keep', 'format' => 'text', 'kind' => 'other']);
        $migration->up();
        $this->assertDatabaseHas('facts', ['id' => $legacyId, 'visibility' => 'scope', 'value' => 'keep']);
        $newId = (string) Str::ulid();
        DB::table('facts')->insert(['id' => $newId, 'scope_id' => $scope->id, 'created_by' => $owner->id, 'label' => 'new', 'value' => 'private', 'format' => 'text', 'kind' => 'other']);
        $this->assertDatabaseHas('facts', ['id' => $newId, 'visibility' => 'private']);
    }

    public function test_private_record_cannot_be_unlinked_by_unrelated_scope_owner_404(): void
    {
        [$owner, $member, $scope, $base] = $this->workspace();
        $contact = Contact::factory()->create(['scope_id' => $scope->id, 'created_by' => $member->id]);
        $fact = Fact::factory()->create(['scope_id' => $scope->id, 'created_by' => $member->id]);
        $link = EntityLink::create(['scope_id' => $scope->id, 'created_by' => $member->id, 'source_type' => 'contact', 'source_id' => $contact->id, 'target_type' => 'fact', 'target_id' => $fact->id]);
        $this->deleteJson("$base/links/{$link->id}")->assertNotFound();
        $this->assertDatabaseHas('entity_links', ['id' => $link->id]);
    }

    public function test_link_list_omits_hidden_project_on_either_side_and_secret_candidates_are_metadata_only(): void
    {
        [$owner, $member, $scope, $base] = $this->workspace();
        $project = Project::factory()->create(['scope_id' => $scope->id, 'visibility' => 'private', 'created_by' => $owner->id]);
        $fact = Fact::factory()->create(['scope_id' => $scope->id, 'created_by' => $member->id, 'is_sensitive' => true, 'value' => 'NO-LINK-LEAK']);
        EntityLink::create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'source_type' => 'fact', 'source_id' => $fact->id, 'target_type' => 'project', 'target_id' => $project->id]);
        $this->actingAs($member)->getJson("$base/links?subject_type=fact&subject_id={$fact->id}")->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson("$base/links/candidates?type=fact")->assertOk()->assertJsonCount(1, 'data')->assertDontSee('NO-LINK-LEAK');
    }

    public function test_same_member_loses_native_fact_access_after_explicit_deny(): void
    {
        [$owner, $member, $scope, $base] = $this->workspace();
        $fact = Fact::factory()->create(['scope_id' => $scope->id, 'created_by' => $member->id]);
        $this->actingAs($member)->getJson("$base/facts/{$fact->id}")->assertOk();
        $scope->members()->where('user_id', $member->id)->update(['permissions' => ['allow' => [], 'deny' => ['fact.view']]]);
        $this->getJson("$base/facts/{$fact->id}")->assertForbidden();
    }

    public function test_new_fact_and_contact_are_private_and_contact_does_not_create_an_account(): void
    {
        [$owner, , $scope, $base] = $this->workspace();
        $users = User::count();
        $fact = $this->postJson("$base/facts", ['label' => 'Реквизит', 'value' => '123'])->assertCreated()->assertJsonPath('data.visibility', 'private')->json('data.id');
        $contact = $this->postJson("$base/contacts", ['name' => 'Поставщик', 'phone' => '+7000'])->assertCreated()->assertJsonPath('data.visibility', 'private')->json('data.id');

        $this->assertDatabaseHas('facts', ['id' => $fact, 'created_by' => $owner->id, 'scope_id' => $scope->id]);
        $this->assertDatabaseHas('contacts', ['id' => $contact, 'user_id' => null, 'phone' => '+7000']);
        $this->assertSame($users, User::count());
    }

    public function test_private_records_are_hidden_from_colleagues_and_scope_owner_404(): void
    {
        [$owner, $member, $scope, $base] = $this->workspace();
        $fact = Fact::factory()->create(['scope_id' => $scope->id, 'created_by' => $member->id, 'label' => 'private-needle', 'visibility' => 'private']);
        $contact = Contact::factory()->create(['scope_id' => $scope->id, 'created_by' => $member->id, 'name' => 'private-needle']);
        $this->getJson("$base/facts/{$fact->id}")->assertNotFound();
        $this->getJson("$base/contacts/{$contact->id}")->assertNotFound();
        $this->getJson("$base/facts?q=private-needle")->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->getJson("$base/contacts?q=private-needle")->assertJsonCount(0, 'data');
        $this->getJson("$base/search?q=private-needle&types=fact,contact")->assertOk()->assertJsonPath('data.total', 0);
    }

    public function test_sensitive_values_are_absent_from_lists_detail_search_and_model_serialization(): void
    {
        [$owner, , $scope, $base] = $this->workspace();
        $fact = Fact::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'value' => 'SECRET-Z9-UNIQUE', 'is_sensitive' => true]);
        $this->getJson("$base/facts")->assertOk()->assertJsonPath('data.0.value', null)->assertDontSee('SECRET-Z9-UNIQUE');
        $this->getJson("$base/facts/{$fact->id}")->assertJsonPath('data.value', null)->assertDontSee('SECRET-Z9-UNIQUE');
        $this->getJson("$base/facts?q=SECRET-Z9-UNIQUE")->assertJsonPath('meta.total', 0);
        $this->getJson("$base/search?q=SECRET-Z9-UNIQUE&types=fact")->assertJsonPath('data.total', 0);
        $this->assertArrayNotHasKey('value', $fact->toArray());
        $this->postJson("$base/facts/{$fact->id}/reveal")->assertOk()->assertJsonPath('data.value', 'SECRET-Z9-UNIQUE')->assertHeader('Cache-Control', 'no-store, private');
        $this->assertStringNotContainsString('SECRET-Z9-UNIQUE', ActivityLog::get()->toJson());
    }

    public function test_shared_records_are_read_only_until_owner_allows_editing_and_only_owner_can_change_privacy(): void
    {
        [$owner, $member, $scope, $base] = $this->workspace();
        $fact = Fact::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope']);
        $this->actingAs($member);
        $this->getJson("$base/facts/{$fact->id}")->assertOk()->assertJsonPath('data.can_write', false);
        $this->patchJson("$base/facts/{$fact->id}", ['revision' => 1, 'label' => 'Нет'])->assertForbidden();
        $fact->update(['allow_member_editing' => true]);
        $this->patchJson("$base/facts/{$fact->id}", ['revision' => 1, 'label' => 'Да'])->assertOk()->assertJsonPath('data.revision', 2);
        $this->patchJson("$base/facts/{$fact->id}", ['revision' => 2, 'visibility' => 'private'])->assertForbidden();
        $this->assertDatabaseHas('facts', ['id' => $fact->id, 'visibility' => 'scope', 'label' => 'Да', 'revision' => 2]);
    }

    public function test_stale_fact_or_contact_update_returns_409_without_overwriting(): void
    {
        [$owner, , $scope, $base] = $this->workspace();
        $fact = Fact::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'value' => 'keep']);
        $contact = Contact::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $this->patchJson("$base/facts/{$fact->id}", ['revision' => 1, 'label' => 'Новый'])->assertOk();
        $this->patchJson("$base/facts/{$fact->id}", ['revision' => 1, 'value' => 'lost'])->assertConflict();
        $this->patchJson("$base/contacts/{$contact->id}", ['revision' => 1, 'name' => 'Новый'])->assertOk();
        $this->patchJson("$base/contacts/{$contact->id}", ['revision' => 1, 'name' => 'lost'])->assertConflict();
        $this->assertDatabaseHas('facts', ['id' => $fact->id, 'value' => 'keep']);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'name' => 'Новый']);
    }

    public function test_revoked_project_membership_overrides_creator_and_all_projects_mode(): void
    {
        [$owner, $member, $scope, $base] = $this->workspace();
        $project = Project::factory()->create(['scope_id' => $scope->id, 'created_by' => $member->id]);
        $fact = Fact::factory()->create(['scope_id' => $scope->id, 'created_by' => $member->id, 'project_id' => $project->id, 'visibility' => 'project']);
        ProjectMember::factory()->create(['project_id' => $project->id, 'user_id' => $member->id, 'is_active' => false]);
        $this->actingAs($member)->getJson("$base/facts/{$fact->id}")->assertNotFound();
        $this->getJson("$base/facts")->assertJsonPath('meta.total', 0);
    }

    public function test_cross_scope_project_and_account_links_are_rejected_422(): void
    {
        [, , , $base] = $this->workspace();
        $project = Project::factory()->create();
        $foreignUser = User::factory()->create();
        $this->postJson("$base/facts", ['label' => 'x', 'value' => 'x', 'visibility' => 'project', 'project_id' => $project->id])->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->postJson("$base/contacts", ['name' => 'x', 'user_id' => $foreignUser->id])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->postJson("$base/facts", ['label' => 'x', 'value' => 'x', 'visibility' => 'project'])->assertUnprocessable()->assertJsonValidationErrors('project_id');
        $this->assertDatabaseCount('facts', 0);
        $this->assertDatabaseCount('contacts', 0);
    }

    public function test_contacts_keep_account_link_notes_and_soft_delete_restore_without_deleting_user(): void
    {
        [$owner, $member, $scope, $base] = $this->workspace();
        $contact = $this->postJson("$base/contacts", ['name' => 'Коллега', 'user_id' => $member->id, 'note_md' => '**Заметка**'])->assertCreated()->json('data.id');
        $note = $this->postJson("$base/contacts/$contact/notes", ['content' => 'История разговора'])->assertCreated()->json('data.id');
        $this->getJson("$base/contacts/$contact/notes")->assertJsonPath('data.0.content', 'История разговора');
        $this->deleteJson("$base/contacts/$contact/notes/$note")->assertNoContent();
        $this->assertSoftDeleted('contact_notes', ['id' => $note]);
        $this->deleteJson("$base/contacts/$contact", ['revision' => 1])->assertNoContent();
        $this->assertSoftDeleted('contacts', ['id' => $contact]);
        $this->getJson("$base/contacts")->assertJsonPath('meta.total', 0);
        $this->postJson("$base/contacts/$contact/restore", ['revision' => 2])->assertOk()->assertJsonPath('data.revision', 3);
        $this->assertNotSoftDeleted('contacts', ['id' => $contact]);
        $this->assertNotSoftDeleted($member);
    }

    public function test_fact_delete_restore_and_pagination_do_not_lose_data(): void
    {
        [$owner, , $scope, $base] = $this->workspace();
        Fact::factory()->count(51)->create(['scope_id' => $scope->id, 'created_by' => $owner->id]);
        $this->getJson("$base/facts")->assertJsonCount(50, 'data')->assertJsonPath('meta.total', 51);
        $fact = $this->getJson("$base/facts?page=2")->assertJsonCount(1, 'data')->json('data.0');
        $this->deleteJson("$base/facts/{$fact['id']}", ['revision' => 1])->assertNoContent();
        $this->getJson("$base/facts?trash=1")->assertJsonCount(1, 'data')->assertJsonPath('data.0.revision', 2);
        $this->postJson("$base/facts/{$fact['id']}/restore", ['revision' => 2])->assertOk()->assertJsonPath('data.revision', 3);
        $this->assertDatabaseCount('facts', 51);
    }

    public function test_private_links_are_hidden_on_both_sides_even_for_scope_owner(): void
    {
        [$owner, $member, $scope, $base] = $this->workspace();
        $contact = Contact::factory()->create(['scope_id' => $scope->id, 'created_by' => $member->id]);
        $fact = Fact::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope', 'is_sensitive' => true, 'value' => 'UNSEEN']);
        $link = EntityLink::create(['scope_id' => $scope->id, 'created_by' => $member->id, 'source_type' => 'contact', 'source_id' => $contact->id, 'target_type' => 'fact', 'target_id' => $fact->id, 'relation' => 'related']);
        $this->getJson("$base/links?subject_type=fact&subject_id={$fact->id}")->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->postJson("$base/links", ['source_type' => 'fact', 'source_id' => $fact->id, 'target_type' => 'contact', 'target_id' => $contact->id])->assertNotFound();
        $this->actingAs($member)->getJson("$base/links?subject_type=contact&subject_id={$contact->id}")->assertOk()->assertJsonCount(1, 'data')->assertDontSee('UNSEEN');
        $this->assertDatabaseHas('entity_links', ['id' => $link->id]);
    }

    public function test_agent_token_without_reveal_cannot_read_or_unmask_sensitive_value_and_traces_are_redacted(): void
    {
        [$owner, , $scope] = $this->workspace();
        $agent = User::factory()->agent()->create(['status' => 'active', 'is_active' => true]);
        $scope->members()->create(['user_id' => $agent->id, 'role' => 'member', 'is_active' => true, 'project_access_mode' => 'all', 'joined_at' => now()]);
        $token = $agent->createToken('factor-test', ['fact.view', 'fact.write', 'contact.view', 'contact.write']);
        $base = "/api/agent/scopes/{$scope->id}";
        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken);
        $fact = $this->postJson("$base/facts", ['label' => 'secret-label', 'value' => 'SECRET-AGENT-UNIQUE', 'is_sensitive' => true])->assertCreated()->assertJsonPath('data.value', null)->json('data.id');
        $this->getJson("$base/facts/$fact")->assertOk()->assertDontSee('SECRET-AGENT-UNIQUE');
        $this->postJson("$base/facts/$fact/reveal")->assertForbidden();
        $this->patchJson("$base/facts/$fact", ['revision' => 1, 'is_sensitive' => false])->assertForbidden();
        $this->postJson("$base/contacts", ['name' => 'PII-UNIQUE-NAME', 'phone' => 'PII-UNIQUE-PHONE'])->assertCreated();
        $this->assertStringNotContainsString('SECRET-AGENT-UNIQUE', ActivityLog::get()->toJson());
        $this->assertStringNotContainsString('PII-UNIQUE', ActivityLog::get()->toJson());
        $this->assertDatabaseHas('facts', ['id' => $fact, 'is_sensitive' => true, 'revision' => 1]);
    }

    public function test_foreign_scope_and_disabled_modules_refuse_requests(): void
    {
        [$owner, , $scope, $base] = $this->workspace();
        $foreign = Contact::factory()->create();
        $this->getJson("$base/contacts/{$foreign->id}")->assertNotFound();
        $scope->update(['settings' => ['modules' => ['enabled' => ['factor' => false, 'contactor' => false]]]]);
        $this->getJson("$base/facts")->assertForbidden();
        $this->postJson("$base/contacts", ['name' => 'no'])->assertForbidden();
        $this->assertDatabaseMissing('contacts', ['scope_id' => $scope->id, 'name' => 'no']);
    }

    public function test_observer_cannot_write_or_reveal(): void
    {
        [$owner, $member, $scope, $base] = $this->workspace('observer');
        $fact = Fact::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope', 'is_sensitive' => true]);
        $this->actingAs($member)->getJson("$base/facts")->assertOk()->assertJsonPath('data.0.can_write', false);
        $this->postJson("$base/facts/{$fact->id}/reveal")->assertForbidden();
        $this->postJson("$base/contacts", ['name' => 'no'])->assertForbidden();
    }

    public function test_fact_page_does_not_issue_per_record_authorization_queries(): void
    {
        [$owner, $member, $scope, $base] = $this->workspace();
        Fact::factory()->count(40)->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope']);
        $this->actingAs($member);
        DB::enableQueryLog();
        $this->getJson("$base/facts")->assertOk()->assertJsonCount(40, 'data');
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThan(30, $queries);
    }

    public function test_old_agent_logs_do_not_reveal_private_fact_values_through_contractor_activity(): void
    {
        [$owner, $member, $scope, $base] = $this->workspace();
        $fact = Fact::factory()->create(['scope_id' => $scope->id, 'created_by' => $member->id, 'value' => 'LEGACY-SECRET']);
        ActivityLog::create(['scope_id' => $scope->id, 'actor_id' => $member->id, 'subject_type' => 'fact', 'subject_id' => $fact->id,
            'action' => 'fact.updated', 'before' => ['value' => 'LEGACY-SECRET'], 'after' => ['value' => 'NEW-SECRET']]);
        $response = $this->getJson("$base/contractors/{$member->id}/activity")->assertOk()->assertJsonPath('data.0.subject_id', null)->assertJsonPath('data.0.action', 'record.restricted');
        $this->assertStringNotContainsString('LEGACY-SECRET', $response->getContent());
        $this->assertStringNotContainsString('NEW-SECRET', $response->getContent());
        ActivityLog::create(['scope_id' => $scope->id, 'actor_id' => $member->id, 'subject_type' => 'agent_api', 'subject_id' => $member->id,
            'action' => 'agent.api.post', 'after' => ['status' => 201], 'context' => ['path' => "/api/agent/scopes/{$scope->id}/facts/{$fact->id}", 'payload' => ['value' => 'OLD-PAYLOAD-SECRET']]]);
        $response = $this->getJson("$base/contractors/{$member->id}/activity")->assertOk();
        $this->assertStringNotContainsString('OLD-PAYLOAD-SECRET', $response->getContent());
        $this->assertStringNotContainsString($fact->id, $response->getContent());
        $this->assertDatabaseHas('facts', ['id' => $fact->id, 'value' => 'LEGACY-SECRET']);
    }

    public function test_partial_end_date_change_cannot_violate_existing_start_date_422(): void
    {
        [$owner, , $scope, $base] = $this->workspace();
        $fact = Fact::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'valid_from' => '2026-10-01', 'valid_to' => '2026-12-01']);
        $this->patchJson("$base/facts/{$fact->id}", ['revision' => 1, 'valid_to' => '2026-09-01'])->assertUnprocessable()->assertJsonValidationErrors('valid_to');
        $this->assertSame('2026-12-01', $fact->fresh()->valid_to->toDateString());
    }

    public function test_anonymous_requests_are_401_and_options_do_not_expose_other_scope_accounts(): void
    {
        [$owner, $member, $scope, $base] = $this->workspace();
        $foreign = User::factory()->create(['name' => 'FOREIGN-ACCOUNT-NAME']);
        $response = $this->getJson("$base/contacts/options")->assertOk()->assertJsonCount(2, 'data.users')->assertJsonPath('data.can_create', true);
        $this->assertStringNotContainsString($foreign->name, $response->getContent());
        $this->app['auth']->forgetGuards();
        $this->getJson("$base/contacts")->assertUnauthorized();
        $this->getJson("$base/facts")->assertUnauthorized();
    }

    public function test_diagram_attachment_requires_native_record_write_access(): void
    {
        [$owner, $member, $scope, $base] = $this->workspace();
        $fact = Fact::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'visibility' => 'scope']);
        $diagram = Diagram::factory()->create(['scope_id' => $scope->id, 'created_by' => $member->id, 'visibility' => 'scope']);
        $this->actingAs($member)->postJson("$base/diagrams/{$diagram->id}/attachments", ['subject_type' => 'fact', 'subject_id' => $fact->id])->assertForbidden();
        $this->assertDatabaseMissing('diagram_attachments', ['subject_type' => 'fact', 'subject_id' => $fact->id]);
    }
}
