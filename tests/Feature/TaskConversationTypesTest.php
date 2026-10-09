<?php

namespace Tests\Feature;

use App\Models\Scope;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class TaskConversationTypesTest extends TestCase
{
    use RefreshDatabase;

    private function workspace(string $status = 'todo'): array
    {
        $owner = User::factory()->create();
        $scope = Scope::factory()->create(['owner_id' => $owner->id]);
        $scope->members()->create(['user_id' => $owner->id, 'role' => 'owner', 'joined_at' => now()]);
        $task = Task::factory()->create(['scope_id' => $scope->id, 'created_by' => $owner->id, 'status' => $status]);
        $this->actingAs($owner)->withHeaders(['Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-App-Request' => 'Zuratax']);

        return [$owner, $scope, $task, "/api/scopes/{$scope->id}/tasks/{$task->id}/comments"];
    }

    public function test_comments_include_author_avatar_without_exposing_other_profile_fields(): void
    {
        [$owner, , , $url] = $this->workspace();
        $avatar = ['preset' => 'Anima_00024_.png', 'crop' => ['x' => 30, 'y' => 60, 'zoom' => 1.5]];
        $owner->update(['profile' => ['avatar' => $avatar, 'private_note' => 'internal']]);
        $id = $this->postJson($url, ['kind' => 'question', 'content' => 'Ready?'])->assertCreated()
            ->assertJsonPath('data.created_by.avatar', $avatar)->assertJsonMissingPath('data.created_by.profile')->json('data.id');
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.created_by.avatar', $avatar)
            ->assertJsonMissingPath('data.0.created_by.profile');
        $this->patchJson("{$url}/{$id}", ['is_answered' => true])->assertOk()->assertJsonPath('data.created_by.avatar', $avatar);
        $owner->update(['profile' => []]);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.created_by.avatar', null);
    }

    public function test_question_answer_and_answer_deletion_update_card_summary_and_audit(): void
    {
        [$owner, $scope, $task, $url] = $this->workspace('done');
        $question = $this->postJson($url, ['kind' => 'question', 'content' => 'Where is the result?'])->assertCreated()
            ->assertJsonPath('data.is_answered', false)->json('data.id');
        $this->getJson("/api/scopes/{$scope->id}/tasks")->assertOk()->assertJsonPath('data.0.unanswered_questions_count', 1);

        $reply = $this->postJson($url, ['kind' => 'answer', 'parent_id' => $question, 'content' => 'In Booker.'])->assertCreated()->json('data.id');
        $this->assertDatabaseHas('comments', ['id' => $question, 'is_answered' => true]);
        $this->getJson("/api/scopes/{$scope->id}/tasks")->assertOk()->assertJsonPath('data.0.unanswered_questions_count', 0)->assertJsonPath('data.0.comments_count', 2);
        $this->assertDatabaseHas('activity_logs', ['subject_id' => $task->id, 'actor_id' => $owner->id, 'action' => 'task.question_answered']);

        $this->deleteJson("{$url}/{$reply}")->assertNoContent();
        $this->assertSoftDeleted('comments', ['id' => $reply]);
        $this->assertDatabaseHas('comments', ['id' => $question, 'is_answered' => false]);
        $this->getJson("/api/scopes/{$scope->id}/tasks")->assertOk()->assertJsonPath('data.0.unanswered_questions_count', 1);
    }

    #[TestWith([null])]
    #[TestWith([['preset' => 'Anima_00024_.png', 'crop' => ['x' => 25, 'y' => 75, 'zoom' => 2]]])]
    #[TestWith([['file_id' => '01m1e2jr7p62bd355k3a9tqqbs', 'scope_id' => '01m1e2jr7p62bd355k3a9tqqbt']])]
    public function test_comment_responses_include_author_avatar_without_exposing_the_profile(?array $avatar): void
    {
        [$owner, $scope, $task, $url] = $this->workspace();
        $owner->update(['profile' => ['avatar' => $avatar, 'private_note' => 'Do not expose']]);

        $response = $this->postJson($url, ['kind' => 'question', 'content' => 'Avatar check'])->assertCreated()
            ->assertJsonPath('data.created_by.id', $owner->id)
            ->assertJsonPath('data.created_by.avatar', $avatar)
            ->assertJsonMissingPath('data.created_by.profile');
        $id = $response->json('data.id');
        $this->assertDatabaseHas('comments', ['id' => $id, 'created_by' => $owner->id, 'content' => 'Avatar check']);
        $this->getJson($url)->assertOk()->assertJsonPath('data.0.created_by.avatar', $avatar)
            ->assertJsonMissingPath('data.0.created_by.profile');
        $this->patchJson("{$url}/{$id}", ['is_answered' => true])->assertOk()
            ->assertJsonPath('data.created_by.avatar', $avatar)->assertJsonMissingPath('data.created_by.profile');
    }

    public function test_comments_keep_the_avatar_of_a_soft_deleted_author(): void
    {
        [$owner, $scope, $task, $url] = $this->workspace();
        $author = User::factory()->create(['profile' => ['avatar' => ['preset' => 'Anima_00024_.png']]]);
        $task->comments()->create(['scope_id' => $scope->id, 'created_by' => $author->id, 'content' => 'Historical comment']);
        $author->delete();

        $this->getJson($url)->assertOk()->assertJsonPath('data.0.created_by.id', $author->id)
            ->assertJsonPath('data.0.created_by.avatar.preset', 'Anima_00024_.png');
    }

    public function test_question_can_be_manually_marked_answered_and_reopened(): void
    {
        [, , , $url] = $this->workspace();
        $id = $this->postJson($url, ['kind' => 'question', 'content' => 'Ready?'])->assertCreated()->json('data.id');
        $this->patchJson("{$url}/{$id}", ['is_answered' => true])->assertOk()->assertJsonPath('data.is_answered', true);
        $this->patchJson("{$url}/{$id}", ['is_answered' => false])->assertOk()->assertJsonPath('data.is_answered', false);
        $this->assertDatabaseHas('comments', ['id' => $id, 'is_answered' => false]);
    }

    public function test_only_one_level_of_replies_is_allowed_and_answers_require_questions(): void
    {
        [, , , $url] = $this->workspace();
        $root = $this->postJson($url, ['content' => 'Root'])->assertCreated()->json('data.id');
        $reply = $this->postJson($url, ['content' => 'Reply', 'parent_id' => $root])->assertCreated()->json('data.id');
        $this->postJson($url, ['content' => 'Too deep', 'parent_id' => $reply])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        $this->postJson($url, ['content' => 'Not a question', 'kind' => 'answer', 'parent_id' => $root])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->postJson($url, ['content' => 'No parent', 'kind' => 'answer'])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->postJson($url, ['content' => 'Nested question', 'kind' => 'question', 'parent_id' => $root])->assertUnprocessable()->assertJsonValidationErrors('kind');
        $this->assertDatabaseCount('comments', 2);
    }

    public function test_deleting_question_soft_deletes_its_replies(): void
    {
        [, , , $url] = $this->workspace();
        $question = $this->postJson($url, ['kind' => 'question', 'content' => 'Question'])->assertCreated()->json('data.id');
        $reply = $this->postJson($url, ['kind' => 'answer', 'parent_id' => $question, 'content' => 'Answer'])->assertCreated()->json('data.id');
        $this->deleteJson("{$url}/{$question}")->assertNoContent();
        $this->assertSoftDeleted('comments', ['id' => $question]);
        $this->assertSoftDeleted('comments', ['id' => $reply]);
        $this->getJson($url)->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_cancelled_tasks_have_read_only_discussion(): void
    {
        [, $scope, $task, $url] = $this->workspace('cancelled');
        $comment = $task->comments()->create(['scope_id' => $scope->id, 'created_by' => $task->created_by, 'kind' => 'question', 'content' => 'Old question']);
        $this->postJson($url, ['content' => 'No'])->assertUnprocessable();
        $this->patchJson("{$url}/{$comment->id}", ['is_answered' => true])->assertUnprocessable();
        $this->deleteJson("{$url}/{$comment->id}")->assertUnprocessable();
        $this->getJson($url)->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseHas('comments', ['id' => $comment->id, 'deleted_at' => null, 'is_answered' => false]);
    }

    public function test_another_scope_or_an_unprivileged_colleague_cannot_manage_a_question(): void
    {
        [, $scope, $task, $url] = $this->workspace();
        $question = $this->postJson($url, ['kind' => 'question', 'content' => 'Private'])->assertCreated()->json('data.id');
        $outsider = User::factory()->create();
        $this->actingAs($outsider)->patchJson("{$url}/{$question}", ['is_answered' => true])->assertForbidden();
        $this->assertDatabaseHas('comments', ['id' => $question, 'is_answered' => false]);
        $scope->members()->create(['user_id' => $outsider->id, 'role' => 'admin', 'is_active' => true, 'project_access_mode' => 'all']);
        $this->patchJson("{$url}/{$question}", ['is_answered' => true])->assertForbidden();
        $this->assertDatabaseHas('comments', ['id' => $question, 'is_answered' => false]);
    }

    public function test_agent_can_answer_questions_but_read_only_tokens_cannot(): void
    {
        [, $scope, $task, $url] = $this->workspace();
        $question = $this->postJson($url, ['kind' => 'question', 'content' => 'Agent question'])->assertCreated()->json('data.id');
        $agent = User::factory()->create(['type' => 'agent', 'status' => 'active', 'is_active' => true]);
        $scope->members()->create(['user_id' => $agent->id, 'role' => 'admin', 'is_active' => true, 'project_access_mode' => 'all']);
        $url = "/api/agent/scopes/{$scope->id}/tasks/{$task->id}/comments";
        auth()->guard('web')->logout();
        auth()->forgetGuards();
        $this->withToken($agent->createToken('Read only', ['task.view'])->plainTextToken)->postJson($url, ['kind' => 'answer', 'parent_id' => $question, 'content' => 'Denied'])->assertForbidden();
        $this->assertDatabaseHas('comments', ['id' => $question, 'is_answered' => false]);
        auth()->forgetGuards();
        $this->withToken($agent->createToken('Writer', ['task.view', 'task.update'])->plainTextToken)->postJson($url, ['kind' => 'answer', 'parent_id' => $question, 'content' => 'Verified'])->assertCreated();
        $this->assertDatabaseHas('comments', ['id' => $question, 'is_answered' => true]);
    }
}
