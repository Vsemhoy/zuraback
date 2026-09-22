<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\LoreEntry;
use App\Models\LoreRevision;
use App\Models\Scope;
use App\Models\ScopeMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractorTokenArchiveTest extends TestCase
{
    use RefreshDatabase;

    private function prepare(): array
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $agent = User::factory()->agent()->create(['created_by' => $owner->id]);
        ScopeMember::factory()->create(['scope_id' => $scope->id, 'user_id' => $agent->id, 'project_access_mode' => 'all']);
        $this->actingAs($owner)->withHeader('X-App-Request', 'Zuratax');

        return [$owner, $scope, $agent, "/api/scopes/{$scope->id}/contractors/{$agent->id}"];
    }

    public function test_revocation_preserves_metadata_and_comments_but_blocks_authentication(): void
    {
        [$owner, $scope, $agent, $url] = $this->prepare();
        $issued = $agent->createToken('Office computer', ['task.view']);
        $token = $issued->accessToken;
        $this->deleteJson($url.'/tokens/'.$token->id)->assertNoContent();
        $revokedAt = $token->fresh()->revoked_at;
        $this->assertNotNull($revokedAt);
        $this->deleteJson($url.'/tokens/'.$token->id)->assertNoContent();
        $this->assertSame($revokedAt, $token->fresh()->revoked_at);
        $this->patchJson($url.'/tokens/'.$token->id, ['comment' => 'Old WMS topic'])->assertOk();
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token->id, 'comment' => 'Old WMS topic']);
        $this->getJson("/api/scopes/{$scope->id}/contractors")->assertJsonFragment(['comment' => 'Old WMS topic']);
        auth()->guard('web')->logout();
        auth()->forgetGuards();
        $this->withToken($issued->plainTextToken)->getJson('/api/agent/me')->assertUnauthorized();
    }

    public function test_revoking_one_connection_does_not_revoke_another(): void
    {
        [, , $agent, $url] = $this->prepare();
        $first = $agent->createToken('first', ['task.view']);
        $second = $agent->createToken('second', ['task.view']);
        $this->deleteJson($url.'/tokens/'.$first->accessToken->id)->assertNoContent();
        auth()->guard('web')->logout();
        auth()->forgetGuards();
        $this->withToken($second->plainTextToken)->getJson('/api/agent/me')->assertOk();
    }

    public function test_other_contractors_keys_cannot_be_changed_or_used_as_trace_filter(): void
    {
        [, $scope, $agent, $url] = $this->prepare();
        $other = User::factory()->agent()->create();
        $foreign = $other->createToken('foreign', ['task.view'])->accessToken;
        $this->deleteJson($url.'/tokens/'.$foreign->id)->assertNotFound();
        $this->patchJson($url.'/tokens/'.$foreign->id, ['comment' => 'no'])->assertNotFound();
        $this->getJson($url.'/activity?token_id='.$foreign->id)->assertNotFound();
        $colleague = User::factory()->create();
        ScopeMember::factory()->create(['scope_id' => $scope->id, 'user_id' => $colleague->id, 'role' => 'observer']);
        $own = $agent->createToken('own', ['task.view'])->accessToken;
        $this->actingAs($colleague)->deleteJson($url.'/tokens/'.$own->id)->assertForbidden();
        $this->getJson($url.'/activity')->assertForbidden();
    }

    public function test_activity_cursor_pages_do_not_repeat_rows_when_new_logs_arrive(): void
    {
        [, $scope, $agent, $url] = $this->prepare();
        $this->freezeTime();
        $expected = [];
        for ($index = 0; $index < 65; $index++) {
            $expected[] = ActivityLog::query()->create([
                'scope_id' => $scope->id, 'actor_id' => $agent->id, 'subject_type' => 'agent_api',
                'subject_id' => $agent->id, 'action' => 'agent.api.get',
            ])->id;
        }
        $page = $this->getJson($url.'/activity')->assertOk()->assertJsonCount(30, 'data')->json();
        $seen = array_column($page['data'], 'id');
        $this->travel(1)->seconds();
        $new = ActivityLog::query()->create([
            'scope_id' => $scope->id, 'actor_id' => $agent->id, 'subject_type' => 'agent_api',
            'subject_id' => $agent->id, 'action' => 'agent.api.get',
        ]);
        for ($index = 0; $index < 3 && $page['meta']['has_more']; $index++) {
            $cursor = $page['meta']['next_cursor'];
            $page = $this->getJson($url.'/activity?'.http_build_query(['cursor_at' => $cursor['at'], 'cursor_id' => $cursor['id']]))->assertOk()->json();
            $seen = [...$seen, ...array_column($page['data'], 'id')];
        }
        $this->assertCount(65, $seen);
        $this->assertSame(count($seen), count(array_unique($seen)));
        $this->assertEqualsCanonicalizing($expected, $seen);
        $this->assertNotContains($new->id, $seen);
        $this->assertFalse($page['meta']['has_more']);
    }

    public function test_archived_key_trace_is_filtered_by_key_and_scope(): void
    {
        [, $scope, $agent, $url] = $this->prepare();
        $first = $agent->createToken('first', ['task.view'])->accessToken;
        $second = $agent->createToken('second', ['task.view'])->accessToken;
        $other = Scope::factory()->create();
        foreach ([[$scope, $first], [$scope, $second], [$other, $first]] as [$logScope, $token]) {
            ActivityLog::query()->create(['scope_id' => $logScope->id, 'actor_id' => $agent->id,
                'subject_type' => 'agent_api', 'subject_id' => $agent->id, 'action' => 'agent.api.get', 'context' => ['token_id' => $token->id]]);
        }
        $this->deleteJson($url.'/tokens/'.$first->id)->assertNoContent();
        $this->getJson($url.'/activity?token_id='.$first->id)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.context.token_id', $first->id);
        $this->getJson($url.'/activity?cursor_id=invalid')->assertUnprocessable();
        $this->getJson($url.'/activity?limit=10000')->assertUnprocessable();
    }

    public function test_comment_validation_does_not_silently_clear_or_reactivate_archived_key(): void
    {
        [, , $agent, $url] = $this->prepare();
        $token = $agent->createToken('comment', ['task.view'])->accessToken;
        $this->deleteJson($url.'/tokens/'.$token->id)->assertNoContent();
        $this->patchJson($url.'/tokens/'.$token->id, [])->assertUnprocessable();
        $this->patchJson($url.'/tokens/'.$token->id, ['comment' => str_repeat('x', 501)])->assertUnprocessable();
        $this->patchJson($url.'/tokens/'.$token->id, ['comment' => 'test', 'revoked_at' => null])->assertOk();
        $this->assertNotNull($token->fresh()->revoked_at);
    }

    public function test_mixed_lore_and_api_events_page_in_one_stable_order(): void
    {
        [, $scope, $agent, $url] = $this->prepare();
        $this->freezeTime();
        $entry = LoreEntry::query()->create(['scope_id' => $scope->id, 'created_by' => $agent->id, 'code' => 'TEST']);
        $revision = LoreRevision::query()->create(['lore_entry_id' => $entry->id, 'created_by' => $agent->id, 'version' => 1, 'title' => 'Decision', 'content' => 'Not needed in trace', 'effective_from' => now()]);
        $log = ActivityLog::query()->create(['scope_id' => $scope->id, 'actor_id' => $agent->id, 'subject_type' => 'agent_api', 'subject_id' => $agent->id, 'action' => 'agent.api.get']);
        $first = $this->getJson($url.'/activity?limit=1')->assertOk()->assertJsonCount(1, 'data')->json();
        $cursor = $first['meta']['next_cursor'];
        $second = $this->getJson($url.'/activity?'.http_build_query(['limit' => 1, 'cursor_at' => $cursor['at'], 'cursor_id' => $cursor['id']]))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.has_more', false)->json();
        $this->assertEqualsCanonicalizing([$log->id, 'lore-'.$revision->id], [$first['data'][0]['id'], $second['data'][0]['id']]);
        $this->assertArrayNotHasKey('content', $second['data'][0]['after'] ?? []);
    }
}
